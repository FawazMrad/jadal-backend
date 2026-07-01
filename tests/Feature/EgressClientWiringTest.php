<?php

namespace Tests\Feature;

use Agence104\LiveKit\EgressServiceClient;
use Agence104\LiveKit\EncodedOutputs;
use Agence104\LiveKit\RoomServiceClient;
use App\Services\LiveKitService;
use Livekit\EgressInfo;
use Livekit\EncodedFileType;
use Livekit\ParticipantInfo;
use Livekit\TrackInfo;
use Livekit\TrackSource;
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
    public function test_start_track_egress_calls_track_composite_egress_with_audio_only_mp3(): void
    {
        $micTrack = (new TrackInfo())->setSid('TR_audio1')->setSource(TrackSource::MICROPHONE);
        $participant = (new ParticipantInfo())->setTracks([$micTrack]);

        $room = Mockery::mock(RoomServiceClient::class);
        $room->shouldReceive('getParticipant')
            ->once()
            ->with('debate-deb-main', '26')
            ->andReturn($participant);

        $egress = Mockery::mock(EgressServiceClient::class);
        $egress->shouldReceive('startTrackCompositeEgress')
            ->once()
            ->withArgs(function (string $roomName, $output, string $audioTrackId, string $videoTrackId): bool {
                // Wrapped in EncodedOutputs (file only) so the SDK emits just
                // file_outputs — never the deprecated singular `file` field.
                return $roomName === 'debate-deb-main'
                    && $output instanceof EncodedOutputs
                    && $output->getFile() !== null
                    && $output->getFile()->getFilepath() === '/out/103/stage-2-26.mp3'
                    && $output->getFile()->getFileType() === EncodedFileType::MP3
                    && $audioTrackId === 'TR_audio1'
                    && $videoTrackId === '';
            })
            ->andReturn((new EgressInfo())->setEgressId('EG_abc123'));

        $svc = Mockery::mock(LiveKitService::class)->makePartial();
        $svc->shouldAllowMockingProtectedMethods();
        $svc->shouldReceive('roomServiceClient')->andReturn($room);
        $svc->shouldReceive('egressServiceClient')->andReturn($egress);

        $id = $svc->startTrackEgressForParticipant('debate-deb-main', '26', 103, 2);

        $this->assertSame('EG_abc123', $id);
    }

    public function test_start_track_egress_throws_when_no_microphone_track_published(): void
    {
        $participant = (new ParticipantInfo())->setTracks([]);

        $room = Mockery::mock(RoomServiceClient::class);
        $room->shouldReceive('getParticipant')->once()->andReturn($participant);

        $svc = Mockery::mock(LiveKitService::class)->makePartial();
        $svc->shouldAllowMockingProtectedMethods();
        $svc->shouldReceive('roomServiceClient')->andReturn($room);

        $this->expectException(\RuntimeException::class);
        $svc->startTrackEgressForParticipant('debate-deb-main', '26', 103, 2);
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
