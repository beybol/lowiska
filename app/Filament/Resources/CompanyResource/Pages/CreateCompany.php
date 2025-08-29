<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\FisheryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Actions\Action;
use App\Helpers\Helper;
use Illuminate\Contracts\View\View;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    public bool $wizard = false;
    public int $companyId;

    public function mount(): void
    {
        parent::mount();
        $this->wizard = request()->query('wizard', false);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        
        return $data;
    }

    protected function getFormActions(): array
    {
        if ($this->wizard) {
            return [
                Action::make('createAndGoToVerification')
                    ->label(__('Next'))
                    ->color('success')
                    ->action(function () {
                        $this->create();

                        return redirect()->to(
                            route('filament.owner.pages.verify-company'),
                        );
                    }),
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->color('danger')
                    ->url(url()->previous()),
            ];
        }

        return [
            Action::make('createAndAddFishery')
                ->label(__('Create and add fishery'))
                ->hidden(!Helper::isOwnerPanel())
                ->color('success')
                ->action(function () {
                    $this->create();
                    
                    return redirect()->to(FisheryResource::getUrl('create'));
                }),
            $this->getCreateFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    public function getHeader(): ?View
    {
        if ($this->wizard) {
            $companies = auth()->user()->companies()->get();

            return view('components.fishery-wizard-company-header', [
                'wizard' => $this->wizard,
                'companies' => $companies,
            ]);
        }

        return parent::getHeader();
    }

    protected function getViewData(): array
    {
        return [
            'wizard' => $this->wizard,
        ];
    }
}
