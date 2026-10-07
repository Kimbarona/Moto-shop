<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Audit-log create / update / delete of every fillable attribute (before & after values).
 * The IP address is added globally in AppServiceProvider.
 */
trait LogsChanges
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept($this->auditExcept())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(class_basename($this));
    }

    /**
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return [];
    }
}
