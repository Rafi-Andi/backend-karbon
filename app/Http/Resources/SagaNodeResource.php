<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu node peta Saga: 1 misi quiz + status progres user.
 * today_quiz_id hanya diisi pada node playable (anti-bocor).
 * is_completed persisten (pernah selesai, tidak hangus bila skip hari).
 */
class SagaNodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mission = $this->resource['mission'];
        $position = $this->resource['position'];
        $quizzesCount = $this->resource['quizzes_count'];
        $isCompletedToday = $this->resource['is_completed_today'] ?? false;
        $isCompleted = $this->resource['is_completed'] ?? $isCompletedToday;
        $isPlayableToday = $this->resource['is_playable_today'] ?? false;
        $todayQuizId = $this->resource['today_quiz_id'] ?? null;

        $data = [
            'id' => $mission->id,
            'title' => $mission->title,
            'description' => $mission->description,
            'icon' => $mission->icon,
            'xp_reward' => $mission->xp_reward ?? 0,
            'position' => $position,
            'quizzes_count' => $quizzesCount,
            'total_questions' => $this->resource['total_questions'] ?? $quizzesCount,
            'answered_today' => $this->resource['answered_today'] ?? 0,
            'is_completed_today' => $isCompletedToday,
            'is_completed' => $isCompleted,
            'is_playable_today' => $isPlayableToday,
        ];

        if ($isPlayableToday && $todayQuizId !== null) {
            $data['today_quiz_id'] = $todayQuizId;
        }

        return $data;
    }
}
