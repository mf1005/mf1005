<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ReviewService
{
    /**
     * 口コミを更新し、DBとキャッシュの整合性を保つ。永続キャッシュは夜間バッチで実施。
     * 
     * @param int $reviewId 更新対象のID
     * @param array $updateData 更新する内容
     */
　　　public function updateReview($reviewId, array $updateData)
　　　{
　　　    DB::transaction(function () use ($reviewId, $updateData) {

              // 中略
              // 口コミレコードのUPDATE/DELETE等
　　　        $latestRecord = DB::table('reviews')->find($reviewId);
　　　
　　　        // バッチ実行中の時だけ差分管理テーブルに更新
　　　        $isBatchRunning = Cache::get('process_flag') == 1;
　　　
　　　        if ($isBatchRunning) {
　　　            DB::table('review_diff')->updateOrInsert(
　　　                ['ID' => $reviewId], 
　　　                (array)$latestRecord
　　　            );
　　　        }
　　　
　　　        // 現在のキャッシュに反映
　　　        $this->syncToCache($latestRecord);
　　　    });
　　　}

    /**
     * 現在のキャッシュに反映
     */
    private function syncToCache($record)
    {
        $opponentId = $record->Opponent_User_ID;
        $cacheKey = (string)$opponentId;

        $existingJson = Cache::get($cacheKey);
        $dataMap = [];

        if ($existingJson) {
            $decoded = json_decode($existingJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    $dataMap[$row['ID']] = $row;
                }
            }
        }

        $dataMap[$record->ID] = (array)$record;

        Cache::forever($cacheKey, json_encode(array_values($dataMap)));
    }
}


