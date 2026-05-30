<?php

namespace App\Http\Requests\AppConfig;

class RestoreSystemDatabaseBackupRequest extends AppConfigFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'required|integer|min:1',
            'backup_file' => 'required|file|max:51200',
        ];
    }
}