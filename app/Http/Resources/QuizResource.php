<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $showAnswer = $this->resource['show_answer'] ?? false;
        $quiz = $this->resource['quiz'];
        $mission = $quiz->mission;

        $data = [
            'id' => $quiz->id,
            'mission_id' => $quiz->mission_id,
            'mission_title' => $mission?->title,
            'question' => $quiz->question,
            'options' => $quiz->options,
            'order' => $quiz->order,
            'is_completed_today' => $this->resource['is_completed_today'] ?? false,
            'xp_reward' => $mission?->xp_reward ?? 0,
        ];

        if ($showAnswer) {
            $data['correct_answer'] = $quiz->correct_answer;
            $data['explanation'] = $quiz->explanation;
        }

        return $data;
    }
}
