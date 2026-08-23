<?php

namespace App\Console\Commands;

use App\Models\BlogPost;
use App\Services\Push\DebateNotifier;
use Illuminate\Console\Command;

/**
 * Weekly blog digest, sent ONLY if at least one article was
 * published in the past week. Scheduled Saturday 18:00 Asia/Damascus (see
 * routes/console.php) because the debate week here starts Sunday.
 *
 * "Published" = approved and visible, dated by the moment it became visible.
 */
class SendBlogWeeklyDigest extends Command
{
    protected $signature = 'push:blog-digest';

    protected $description = 'Send the weekly blog digest push if any article was published in the past week.';

    public function handle(DebateNotifier $notifier): int
    {
        $since = now()->subWeek();

        $count = BlogPost::where('status', 'published')
            ->where('updated_at', '>=', $since)
            ->count();

        if ($count === 0) {
            $this->info('No articles published this week — digest skipped.');

            return self::SUCCESS;
        }

        $notifier->blogWeeklyDigest($count);

        $this->info("Weekly digest sent for {$count} article(s).");

        return self::SUCCESS;
    }
}
