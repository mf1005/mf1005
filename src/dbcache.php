<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class DBCache extends Command
{
    protected $signature = 'db:cache-profiles';
    protected $description = 'DBの事前キャッシュ生成とバッチ実行中の差分マージ';

    public function handle()
    {
        // クエリログを無効化（メモリリーク防止）
        DB::disableQueryLog();

        $batchStartTime = Carbon::now();

        try {
            Cache::forever('process_flag', 1);
            $this->info("Batch started at: {$batchStartTime}");

            // Step A: ベース構築
            $this->info('Step A: Rebuilding from profile_master...');
            $this->processTableAndCache('profile_master', null, false);

            // Step B: 差分更新
            $this->info("Step B: Merging from review_diff...");
            $this->processTableAndCache('review_diff', $batchStartTime, true);

            $this->info('completed.');

        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error('DBCache Batch Error: ' . $e->getMessage());
        } finally {
            Cache::forever('process_flag', 0);
            $this->info('Batch finished: Flag set to 0');
        }

        return 0;
    }

    private function processTableAndCache($tableName, $startTime = null, $isMergeMode = false)
    {
        $currentOpponentId = null;
        $tempGroup = [];

        $query = DB::table($tableName);
        if ($startTime) {
            $query->where('updated_at', '>=', $startTime);
        }

        $query->orderBy('Opponent_User_ID')
            ->chunk(1000, function ($records) use (&$currentOpponentId, &$tempGroup, $isMergeMode) {
                foreach ($records as $record) {
                    
                    if (is_null($currentOpponentId)) {
                        $currentOpponentId = $record->Opponent_User_ID;
                    }

                    if ($record->Opponent_User_ID !== $currentOpponentId) {
                        $this->finalizeMerge($currentOpponentId, $tempGroup, $isMergeMode);
                        $tempGroup = [];
                        $currentOpponentId = $record->Opponent_User_ID;
                    }

                    $tempGroup[$record->ID] = (array)$record;
                }

                // 念のため各チャンク終了時に明示的にメモリ解放を促す
                unset($records);
            });

        if (!is_null($currentOpponentId)) {
            $this->finalizeMerge($currentOpponentId, $tempGroup, $isMergeMode);
        }
    }

    private function finalizeMerge($opponentId, array $newRecords, $isMergeMode)
    {
        $cacheKey = (string)$opponentId;
        $dataMap = [];

        if ($isMergeMode) {
            $existingJson = Cache::get($cacheKey);
            if ($existingJson) {
                $decoded = json_decode($existingJson, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $row) {
                        $dataMap[$row['ID']] = $row;
                    }
                }
                unset($decoded);
            }
        }

        foreach ($newRecords as $id => $data) {
            $dataMap[$id] = $data;
        }

        Cache::forever($cacheKey, json_encode(array_values($dataMap)));

        unset($dataMap);
    }
}