<?php

namespace App\Http\Requests\WebServer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSiteTuningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'max_conns' => ['required', 'integer', 'min:1', 'max:500'],
            'init_timeout' => ['required', 'integer', 'min:5', 'max:3600'],
            'persist_conn' => ['required', 'boolean'],
            'pc_keep_alive_timeout' => ['required', 'integer', 'min:0', 'max:3600'],
            'mem_soft_limit_mb' => ['required', 'integer', 'min:64', 'max:65536'],
            'mem_hard_limit_mb' => ['required', 'integer', 'min:64', 'max:65536'],
            'proc_soft_limit' => ['required', 'integer', 'min:10', 'max:4096'],
            'proc_hard_limit' => ['required', 'integer', 'min:10', 'max:4096'],
            'php_memory_limit_mb' => ['required', 'integer', 'min:32', 'max:32768'],
            'php_max_execution_time' => ['required', 'integer', 'min:1', 'max:3600'],
            'php_max_input_time' => ['required', 'integer', 'min:1', 'max:3600'],
            'php_upload_max_filesize_mb' => ['required', 'integer', 'min:1', 'max:10240'],
            'php_post_max_size_mb' => ['required', 'integer', 'min:1', 'max:10240'],
            'php_max_input_vars' => ['required', 'integer', 'min:100', 'max:100000'],
            'php_display_errors' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $this->all();

            $memSoft = (int) ($data['mem_soft_limit_mb'] ?? 0);
            $memHard = (int) ($data['mem_hard_limit_mb'] ?? 0);
            $procSoft = (int) ($data['proc_soft_limit'] ?? 0);
            $procHard = (int) ($data['proc_hard_limit'] ?? 0);
            $phpMem = (int) ($data['php_memory_limit_mb'] ?? 0);
            $uploadMax = (int) ($data['php_upload_max_filesize_mb'] ?? 0);
            $postMax = (int) ($data['php_post_max_size_mb'] ?? 0);

            if ($memHard < $memSoft) {
                $validator->errors()->add('mem_hard_limit_mb', 'Memory hard limit must be greater than or equal to soft limit.');
            }

            if ($procHard < $procSoft) {
                $validator->errors()->add('proc_hard_limit', 'Process hard limit must be greater than or equal to soft limit.');
            }

            if ($postMax < $uploadMax) {
                $validator->errors()->add('php_post_max_size_mb', 'post_max_size must be greater than or equal to upload_max_filesize.');
            }

            if ($memHard < $phpMem + 64) {
                $validator->errors()->add('mem_hard_limit_mb', 'OS memory hard limit must be at least php_memory_limit_mb + 64 MB.');
            }
        });
    }
}
