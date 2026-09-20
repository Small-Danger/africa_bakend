<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        ?User $actor,
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        string $channel = 'admin',
    ): ActivityLog {
        return ActivityLog::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
            'channel' => $channel,
        ]);
    }
}
