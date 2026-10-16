<?php

namespace App\Http\Requests;

use App\Enums\TeamPermission;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('current_team');

        return $team instanceof Team
            && $this->user()?->hasTeamPermission($team, TeamPermission::ImportInvoices);
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:xlsx', 'max:20480']];
    }
}
