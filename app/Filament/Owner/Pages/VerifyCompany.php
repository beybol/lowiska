<?php

namespace App\Filament\Owner\Pages;

use Filament\Pages\Page;

class VerifyCompany extends Page
{
    protected string $view = 'filament.owner.pages.verify-company';

    public function getTitle(): string
    {
        return __('Create fishery wizard');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
