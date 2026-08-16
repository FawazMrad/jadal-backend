<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Admin-only management of the database backups produced by the weekly
 * mysqldump cron. This controller does NOT schedule or expose on-demand
 * backups — it lists, downloads, restores and deletes what the cron already
 * wrote to disk.
 *
 * The one exception is deliberate and required: `restore` takes a fresh dump of
 * the CURRENT database first, so a mistaken restore is reversible. That dump is
 * an internal safety step, not a user-facing "create backup" feature.
 *
 * ─── Security model ────────────────────────────────────────────────────────
 * Every filename arriving from the client is matched against FILENAME_PATTERN
 * before it is used for anything. The pattern admits no `/`, no `\` and no `..`,
 * so a validated name cannot escape BACKUP_DIR. `resolvePath()` additionally
 * re-checks the resolved realpath against the directory — defence in depth, in
 * case the pattern is ever loosened.
 *
 * Every value interpolated into a shell command goes through escapeshellarg().
 * The DB PASSWORD is the deliberate exception: it is passed via the MYSQL_PWD
 * environment variable rather than as a `--password=` argument. Escaping would
 * make it injection-safe but NOT confidential — command-line arguments are
 * world-readable in `ps aux`/`/proc/<pid>/cmdline`, so any local user could read
 * the production DB password while a dump runs. MYSQL_PWD keeps it out of the
 * process table entirely and is understood by both mysqldump and mysql.
 *
 * ─── Why `bash -c` and `set -o pipefail` ───────────────────────────────────
 * Both pipelines here are `producer | consumer`. A shell pipeline reports only
 * the LAST command's exit code, so `mysqldump ... | gzip -c > out.gz` returns 0
 * even when mysqldump dies — producing a perfectly valid gzip of nothing. That
 * failure is silent and is exactly the case that destroys data later, when
 * someone restores from it. `set -o pipefail` makes the pipeline fail if ANY
 * stage fails, and it requires bash (Symfony's default `/bin/sh` is dash on
 * Debian/Ubuntu, which does not support it).
 */
class BackupController extends Controller
{
    /**
     * Backup directory, without a trailing slash. www-data needs read+write
     * here (write is required for the pre-restore safety dump and for delete).
     *
     * Read through config() with a literal default so a `config/backups.php`
     * can override it later without touching this class; no config file is
     * required for it to work today.
     */
    private const DEFAULT_DIR = '/var/backups/jadal-db';

    /** Long enough for a large dump/restore, short enough to not hang a worker forever. */
    private const PROCESS_TIMEOUT = 300;

    /**
     * The ONLY filenames this controller will ever act on.
     *
     * `jadal_*`              — written by the weekly cron.
     * `safety_pre_restore_*` — written by restore() before it overwrites the DB.
     *   These must be downloadable and restorable, otherwise the safety net is
     *   unusable precisely when it is needed (undoing a bad restore).
     *
     * Anchored at both ends, with no path separators or dots beyond the literal
     * `.sql.gz`, so path traversal is structurally impossible.
     */
    private const FILENAME_PATTERN =
        '/^(?<prefix>jadal|safety_pre_restore)_(?<stamp>\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql\.gz$/';

    /** Timestamp format embedded in a backup filename. */
    private const STAMP_FORMAT = 'Y-m-d_H-i-s';

    /**
     * Guards the destructive paths. A restore that runs concurrently with
     * another restore (or with its own safety dump) will interleave writes and
     * corrupt the database, so these operations are strictly serialised.
     */
    private const LOCK_KEY     = 'backups:restore';
    private const LOCK_SECONDS = 900;

    // ── GET /api/admin/backups ────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $dir = $this->backupDir();

        if (! is_dir($dir) || ! is_readable($dir)) {
            return $this->error(
                'تعذّر قراءة مجلد النسخ الاحتياطية. | The backup directory is not readable.',
                ['directory' => $dir],
                500
            );
        }

        $entries = [];

        foreach ((array) scandir($dir) as $filename) {
            if (! is_string($filename) || ! preg_match(self::FILENAME_PATTERN, $filename, $m)) {
                continue; // ignore anything not matching the two known patterns
            }

            $path = $dir . DIRECTORY_SEPARATOR . $filename;

            if (! is_file($path)) {
                continue;
            }

            $bytes = @filesize($path);
            $bytes = $bytes === false ? 0 : $bytes;

            $createdAt = $this->parseStamp($m['stamp']);

            $entries[] = [
                'filename'   => $filename,
                // 'scheduled' = the weekly cron; 'safety' = auto-taken before a restore.
                'type'       => $m['prefix'] === 'jadal' ? 'scheduled' : 'safety',
                'size'       => $this->humanSize($bytes),
                'size_bytes' => $bytes,
                'created_at' => $createdAt?->toIso8601String(),
            ];
        }

        // Newest first. A filename with an impossible date (e.g. month 99) parses
        // to null; those sort last rather than blowing up the sort.
        usort($entries, static function (array $a, array $b): int {
            return [$b['created_at'] === null ? 0 : 1, (string) $b['created_at']]
               <=> [$a['created_at'] === null ? 0 : 1, (string) $a['created_at']];
        });

        return $this->success([
            'directory' => $dir,
            'count'     => count($entries),
            'backups'   => $entries,
        ], 'تم جلب النسخ الاحتياطية. | Backups retrieved.');
    }

    // ── GET /api/admin/backups/{filename}/download ────────────────────────────

    public function download(string $filename): BinaryFileResponse|JsonResponse
    {
        if (! $this->isValidFilename($filename)) {
            return $this->invalidFilename($filename);
        }

        $path = $this->resolvePath($filename);

        if ($path === null || ! is_readable($path)) {
            return $this->notFound($filename);
        }

        // Streamed by the framework (BinaryFileResponse), so a multi-GB dump is
        // never loaded into PHP memory.
        return response()->download($path, $filename, [
            'Content-Type' => 'application/gzip',
        ]);
    }

    // ── POST /api/admin/backups/{filename}/restore ────────────────────────────

    /**
     * Overwrites the live database with the contents of a backup.
     *
     * Order of operations is the safety contract and must not be reordered:
     *   1. validate the filename
     *   2. require the typed confirmation
     *   3. verify the SOURCE archive is intact   ← before anything destructive
     *   4. dump the CURRENT database to safety_pre_restore_*
     *   5. verify THAT dump is complete          ← before anything destructive
     *   6. only then pipe the backup into mysql
     *
     * Steps 3 and 5 matter more than they look. Without 3, a truncated archive
     * is discovered halfway through the restore, when the schema is already
     * partially dropped. Without 5, the recovery point itself may be unusable —
     * which is the worst case of all, because the operation looks safe right up
     * until the moment someone needs to undo it.
     *
     * Every failure path after step 4 reports the safety backup filename, so an
     * admin staring at a half-restored database is told what to restore rather
     * than having to guess which file is theirs.
     */
    public function restore(Request $request, string $filename): JsonResponse
    {
        if (! $this->isValidFilename($filename)) {
            return $this->invalidFilename($filename);
        }

        // Exact string match — no trimming, no case-folding. This is the
        // deliberate friction that stops a mis-click from wiping the database.
        if ($request->input('confirm') !== 'RESTORE') {
            return $this->error(
                'تأكيد غير صالح. أرسل {"confirm":"RESTORE"} لتأكيد الاستعادة. | '
                . 'Invalid confirmation. Send {"confirm":"RESTORE"} to confirm the restore.',
                ['confirm' => 'Must be exactly the string "RESTORE".'],
                422
            );
        }

        $path = $this->resolvePath($filename);

        if ($path === null || ! is_readable($path)) {
            return $this->notFound($filename);
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->error(
                'هناك عملية استعادة قيد التنفيذ بالفعل. | A restore is already in progress.',
                [],
                409
            );
        }

        // Non-null only once a VERIFIED recovery point exists on disk.
        $safetyName = null;
        // True only once mysql has been invoked against the live database, i.e.
        // once a failure could have left it partially restored.
        $databaseTouched = false;

        try {
            // 3 — reject a corrupt source archive while the database is intact.
            $this->assertArchiveIsIntact($path);

            // 4 — the undo point.
            $candidate  = 'safety_pre_restore_' . now()->format(self::STAMP_FORMAT) . '.sql.gz';
            $safetyPath = $this->backupDir() . DIRECTORY_SEPARATOR . $candidate;
            $this->dumpCurrentDatabase($safetyPath);

            // 5 — a recovery point is worthless unless it is known-good, and
            // this is the last moment it can be checked for free. If it fails,
            // the file is REMOVED: leaving it would put an unusable archive in
            // the backup list that looks like a valid restore point.
            try {
                $this->assertDumpIsComplete($safetyPath);
            } catch (\Throwable $e) {
                @unlink($safetyPath);

                throw new RuntimeException(
                    'The safety backup could not be verified, so the restore was aborted '
                    . 'and the database was left untouched. ' . $e->getMessage()
                );
            }

            $safetyName = $candidate; // only now is it a real recovery point

            // 6 — destructive from here.
            $databaseTouched = true;
            $this->runRestore($path);
        } catch (ProcessTimedOutException) {
            return $this->restoreFailed(
                'انتهت مهلة عملية الاستعادة. | The restore timed out.',
                $filename,
                $safetyName,
                $databaseTouched,
                ['timeout_seconds' => self::PROCESS_TIMEOUT]
            );
        } catch (RuntimeException $e) {
            return $this->restoreFailed(
                'فشلت عملية الاستعادة. | The restore failed. ' . $e->getMessage(),
                $filename,
                $safetyName,
                $databaseTouched
            );
        } catch (\Throwable $e) {
            // Belt and braces: anything not anticipated above must still leave
            // via a clean JSON response. A destructive endpoint must never
            // answer with a raw exception message or stack trace.
            Log::error('Backup restore failed unexpectedly', [
                'filename'  => $filename,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            return $this->restoreFailed(
                'فشلت عملية الاستعادة بسبب خطأ غير متوقع. | The restore failed due to an unexpected error.',
                $filename,
                $safetyName,
                $databaseTouched
            );
        } finally {
            $lock->release();
        }

        return $this->success([
            'restored_from'   => $filename,
            // Restore THIS to undo the operation just performed.
            'safety_backup'   => $safetyName,
            'restored_at'     => now()->toIso8601String(),
        ], 'تمت استعادة قاعدة البيانات. | Database restored successfully.');
    }

    // ── DELETE /api/admin/backups/{filename} ──────────────────────────────────

    public function destroy(string $filename): JsonResponse
    {
        if (! $this->isValidFilename($filename)) {
            return $this->invalidFilename($filename);
        }

        $path = $this->resolvePath($filename);

        if ($path === null) {
            return $this->notFound($filename);
        }

        if (! @unlink($path)) {
            return $this->error(
                'تعذّر حذف النسخة الاحتياطية. | Could not delete the backup file.',
                ['filename' => $filename],
                500
            );
        }

        return $this->success(
            ['filename' => $filename],
            'تم حذف النسخة الاحتياطية. | Backup deleted.'
        );
    }

    // ── Shell operations ──────────────────────────────────────────────────────

    /**
     * `gzip -t` decompresses to /dev/null and verifies the CRC. Cheap insurance
     * against restoring a truncated archive (a cron killed mid-dump, a partial
     * upload, a full disk).
     */
    private function assertArchiveIsIntact(string $path): void
    {
        $this->runShell('gzip -t ' . escapeshellarg($path), 'archive integrity check');
    }

    /**
     * Stronger check, used ONLY on the safety backup this controller just wrote.
     *
     * `gzip -t` proves the container is intact, but not that the SQL inside is
     * complete: a dump killed partway and then gzipped cleanly passes it. Every
     * mysqldump run ends with a `-- Dump completed on …` line, so its presence
     * is positive proof the dump ran to completion rather than being truncated
     * by a killed process, a full disk, or a broken pipe.
     *
     * Deliberately NOT applied to the archive being restored: those come from
     * the external weekly cron, whose mysqldump flags are outside this code's
     * control (`--compact`/`--skip-comments` would omit the marker). Demanding
     * it there could refuse a perfectly good backup, which is a worse failure
     * than the one it prevents. The source archive gets `gzip -t` only.
     *
     * Cost: decompresses the file once more. On a rare, destructive operation
     * that is a fair price for knowing the undo point actually works.
     */
    private function assertDumpIsComplete(string $path): void
    {
        $this->runShell('gzip -t ' . escapeshellarg($path), 'safety backup integrity check');

        // `grep` without -q so it consumes all input; -q exits on first match and
        // can SIGPIPE the upstream stage, which `set -o pipefail` would then
        // report as a failure.
        $this->runShell(
            'gunzip -c ' . escapeshellarg($path) . ' | tail -c 200 | grep "Dump completed" > /dev/null',
            'safety backup completeness check'
        );
    }

    /**
     * Dump the CURRENT database to $target.
     *
     * Writes to `$target.tmp` and renames on success, so an aborted dump never
     * leaves a half-written file sitting in the directory looking like a valid
     * restore point.
     */
    private function dumpCurrentDatabase(string $target): void
    {
        $db  = $this->databaseConfig();
        $tmp = $target . '.tmp';

        $command = 'mysqldump'
            . ' --host=' . escapeshellarg($db['host'])
            . ' --port=' . escapeshellarg((string) $db['port'])
            . ' --user=' . escapeshellarg($db['username'])
            . ' --single-transaction --quick --routines --triggers'
            . ' --default-character-set=utf8mb4'
            . ' ' . escapeshellarg($db['database'])
            . ' | gzip -c > ' . escapeshellarg($tmp);

        try {
            $this->runShell($command, 'safety backup', $db['password']);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        if (! @rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Could not finalise the safety backup file.');
        }
    }

    /** Pipe a gzipped dump into mysql, replacing the current database contents. */
    private function runRestore(string $path): void
    {
        $db = $this->databaseConfig();

        $command = 'gunzip -c ' . escapeshellarg($path)
            . ' | mysql'
            . ' --host=' . escapeshellarg($db['host'])
            . ' --port=' . escapeshellarg((string) $db['port'])
            . ' --user=' . escapeshellarg($db['username'])
            . ' --default-character-set=utf8mb4'
            . ' ' . escapeshellarg($db['database']);

        $this->runShell($command, 'restore', $db['password']);
    }

    /**
     * Run a shell pipeline with pipefail enabled and the DB password supplied
     * out-of-band via MYSQL_PWD (see the class docblock).
     *
     * @throws RuntimeException            on a non-zero exit
     * @throws ProcessTimedOutException    on timeout
     */
    private function runShell(string $command, string $label, ?string $password = null): void
    {
        $env = [];

        if ($password !== null && $password !== '') {
            $env['MYSQL_PWD'] = $password;
        }

        // bash (not sh) because `set -o pipefail` is a bashism, and without it a
        // failing producer in a pipeline is reported as success.
        $process = new Process(
            ['bash', '-c', 'set -o pipefail; ' . $command],
            null,
            $env,
            null,
            self::PROCESS_TIMEOUT
        );

        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        $stderr = trim($process->getErrorOutput());

        // mysqldump/mysql echo the password only if it were on the command line
        // (it is not), but the message can still name the host/user — keep it
        // short and non-sensitive for the API response.
        throw new RuntimeException(sprintf(
            'The %s step failed (exit %d)%s',
            $label,
            $process->getExitCode() ?? -1,
            $stderr === '' ? '.' : ': ' . str($stderr)->limit(300)->value()
        ));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function backupDir(): string
    {
        return rtrim((string) config('backups.path', self::DEFAULT_DIR), '/\\');
    }

    private function isValidFilename(string $filename): bool
    {
        return (bool) preg_match(self::FILENAME_PATTERN, $filename);
    }

    /**
     * Absolute path for an ALREADY-VALIDATED filename, or null when the file
     * does not exist.
     *
     * The realpath re-check is redundant given the pattern (which cannot express
     * a traversal) and is kept deliberately: it means loosening the pattern in
     * future cannot silently turn this into an arbitrary-file-read endpoint.
     */
    private function resolvePath(string $filename): ?string
    {
        $dir  = $this->backupDir();
        $real = realpath($dir . DIRECTORY_SEPARATOR . $filename);

        if ($real === false || ! is_file($real)) {
            return null;
        }

        $realDir = realpath($dir);

        if ($realDir === false || ! str_starts_with($real, $realDir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /**
     * @return array{host:string, port:int|string, database:string, username:string, password:string}
     */
    private function databaseConfig(): array
    {
        // Resolved from the DEFAULT connection rather than hardcoding 'mysql':
        // this project runs on the `mariadb` connection, so a hardcoded lookup
        // would silently read nulls and dump nothing.
        $name       = (string) config('database.default');
        $connection = (array) config("database.connections.{$name}", []);

        $driver = $connection['driver'] ?? null;

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(
                "Backups support MySQL/MariaDB only; the active connection uses the '{$driver}' driver."
            );
        }

        return [
            'host'     => (string) ($connection['host'] ?? '127.0.0.1'),
            'port'     => $connection['port'] ?? 3306,
            'database' => (string) ($connection['database'] ?? ''),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
        ];
    }

    /** Null when the filename carries a syntactically valid but impossible date. */
    private function parseStamp(string $stamp): ?Carbon
    {
        try {
            $parsed = Carbon::createFromFormat(self::STAMP_FORMAT, $stamp);
        } catch (\Throwable) {
            return null;
        }

        // createFromFormat "rolls over" out-of-range parts (month 13 → next year)
        // instead of throwing, so round-trip the value to reject those.
        return ($parsed && $parsed->format(self::STAMP_FORMAT) === $stamp) ? $parsed : null;
    }

    private function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => round($bytes / 1073741824, 2) . ' GB',
            $bytes >= 1048576    => round($bytes / 1048576, 2) . ' MB',
            $bytes >= 1024       => round($bytes / 1024, 2) . ' KB',
            default              => $bytes . ' B',
        };
    }

    /**
     * Single exit point for every restore failure.
     *
     * The important part is `database_modified` + `safety_backup`: an admin
     * looking at a failed restore needs to know whether the live database was
     * touched at all, and if so, exactly which file rolls it back. Without
     * that, a half-restored database and a directory full of similarly-named
     * archives is a genuinely dangerous place to be guessing.
     */
    private function restoreFailed(
        string $message,
        string $filename,
        ?string $safetyName,
        bool $databaseTouched,
        array $extra = []
    ): JsonResponse {
        Log::error('Backup restore failed', [
            'filename'          => $filename,
            'safety_backup'     => $safetyName,
            'database_modified' => $databaseTouched,
            'message'           => $message,
        ]);

        $recovery = match (true) {
            $databaseTouched && $safetyName !== null => sprintf(
                'The database may be partially restored. To roll it back, restore "%s".',
                $safetyName
            ),
            $databaseTouched => 'The database may be partially restored and NO verified safety '
                . 'backup is available. Restore the most recent backup manually.',
            default => 'The database was not modified.',
        };

        return $this->error($message, $extra + [
            'restored_from'     => $filename,
            'database_modified' => $databaseTouched,
            'safety_backup'     => $safetyName,
            'recovery'          => $recovery,
        ], 500);
    }

    private function invalidFilename(string $filename): JsonResponse
    {
        return $this->error(
            'اسم ملف غير صالح. | Invalid backup filename.',
            [
                'filename' => $filename,
                'expected' => 'jadal_YYYY-MM-DD_HH-MM-SS.sql.gz or safety_pre_restore_YYYY-MM-DD_HH-MM-SS.sql.gz',
            ],
            422
        );
    }

    private function notFound(string $filename): JsonResponse
    {
        return $this->error(
            'النسخة الاحتياطية غير موجودة. | Backup file not found.',
            ['filename' => $filename],
            404
        );
    }
}
