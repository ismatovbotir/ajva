<?php

namespace App\Jobs;

use App\Services\AjvaAssistant;
use App\Services\AssistantException;
use App\Support\AjvaMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs one AjvaAI question outside the web request, so a slow AI call can never
 * hit the PHP / web-server timeout. The page polls the cache entry written
 * here (see AjvaRun). Run the worker with --timeout above this job's timeout
 * and keep queue retry_after higher than it (config/queue.php: 90 s default,
 * the job's own limit is below that).
 */
class RunAjvaQuestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 80;

    public int $tries = 1;

    /**
     * @param  array<int, array<string, mixed>>  $contents  Gemini history ending with the new question
     */
    public function __construct(public int $userId, public string $runId, public array $contents) {}

    public function handle(AjvaAssistant $assistant): void
    {
        // With the sync queue this runs inside the web request: lift PHP's own limit.
        @set_time_limit(180);
        @ignore_user_abort(true);

        try {
            $result = $assistant->reply($this->contents);
            $this->store(['status' => 'done', 'contents' => $result['contents'], 'message' => AjvaMessage::fromResult($result)]);
        } catch (AssistantException $e) {
            $this->store(['status' => 'failed', 'error' => $e->getMessage(), 'details' => $e->details]);
        } catch (\Throwable $e) {
            Log::error('AjvaAI run failed', ['exception' => $e]);
            $this->store(['status' => 'failed', 'error' => __('The AI service is not available right now. Try again later.'), 'details' => get_class($e).': '.$e->getMessage()]);
        }
    }

    /** Called by the worker when it kills the job (timeout) or it throws past handle(). */
    public function failed(\Throwable $e): void
    {
        $this->store(['status' => 'failed', 'error' => __('The answer took too long. Try a narrower question (shorter period or one shop).'), 'details' => get_class($e).': '.$e->getMessage()]);
    }

    private function store(array $state): void
    {
        Cache::put(self::key($this->userId, $this->runId), $state, now()->addMinutes(15));
    }

    public static function key(int $userId, string $runId): string
    {
        return "ajva.run.{$userId}.{$runId}";
    }
}
