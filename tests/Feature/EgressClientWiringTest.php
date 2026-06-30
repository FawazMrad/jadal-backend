<?php

namespace Tests\Feature;

use Agence104\LiveKit\EgressServiceClient;
use Agence104\LiveKit\EncodedOutputs;
use App\Services\LiveKitService;
use Livekit\EgressInfo;
use Mockery;
use Tests\TestCase;

/**
 * Proves the egress methods route through the SDK's EgressServiceClient
 * (which posts to /twirp/livekit.Egress/…) with the right arguments — i.e. NOT
 * the old hand-rolled /twirp/livekit.EgressService/… path that 404'd. This is
 * the wiring proof; the live-server confirmation is a tinker/CLI step on the box
 * where LiveKit actually runs (it is not reachable from the test runner).
 */
class EgressClientWiringTest extends TestCase
{
    public function test_start_track_egress_calls_participant_egress_with_file_output(): void
    {
        $egress = Mockery::mock(EgressServiceClient::class);
        $egress->shouldReceive('startParticipantEgress')
            ->once()
            ->withArgs(function (string $room, string $identity, $output): bool {
                // Wrapped in EncodedOutputs (file only) so the SDK emits just
                // file_outputs — never the invalid singular `file`.
                return $room === 'debate-deb-main'
                    && $identity === '26'
                    && $output instanceof EncodedOutputs
                    && $output->getFile() !== null
                    && $output->getFile()->getFilepath() === '/var/recordings/103/stage-2-26.mp4';
            })
            ->andReturn((new EgressInfo())->setEgressId('EG_abc123'));

        $svc = Mockery::mock(LiveKitService::class)->makePartial();
        $svc->shouldAllowMockingProtectedMethods();
        $svc->shouldReceive('egressServiceClient')->andReturn($egress);

        $id = $svc->startTrackEgressForParticipant('debate-deb-main', '26', 103, 2);

        $this->assertSame('EG_abc123', $id);
    }

    public function test_stop_egress_calls_sdk_stop_egress(): void
    {
        $egress = Mockery::mock(EgressServiceClient::class);
        $egress->shouldReceive('stopEgress')
            ->once()
            ->with('EG_abc123')
            ->andReturn(new EgressInfo());

        $svc = Mockery::mock(LiveKitService::class)->makePartial();
        $svc->shouldAllowMockingProtectedMethods();
        $svc->shouldReceive('egressServiceClient')->andReturn($egress);

        $svc->stopEgress('EG_abc123');

        // Reaching here without an exception means the SDK path was invoked.
        $this->assertTrue(true);
    }

    public function test_stop_egress_swallows_already_terminal_errors(): void
    {
        $benign = [
            'twirp error unknown: egress not found',
            'egress with status EGRESS_FAILED cannot be stopped',
        ];

        foreach ($benign as $message) {
            $egress = Mockery::mock(EgressServiceClient::class);
            $egress->shouldReceive('stopEgress')->once()->andThrow(new \RuntimeException($message));

            $svc = Mockery::mock(LiveKitService::class)->makePartial();
            $svc->shouldAllowMockingProtectedMethods();
            $svc->shouldReceive('egressServiceClient')->andReturn($egress);

            $svc->stopEgress('EG_dead'); // must NOT throw for an already-terminal egress
        }

        $this->assertTrue(true);
    }

    public function test_stop_egress_rethrows_real_errors(): void
    {
        $egress = Mockery::mock(EgressServiceClient::class);
        $egress->shouldReceive('stopEgress')->once()
            ->andThrow(new \RuntimeException('twirp error unknown: request timed out'));

        $svc = Mockery::mock(LiveKitService::class)->makePartial();
        $svc->shouldAllowMockingProtectedMethods();
        $svc->shouldReceive('egressServiceClient')->andReturn($egress);

        $this->expectException(\RuntimeException::class);
        $svc->stopEgress('EG_x'); // a real failure must still surface
    }
}
