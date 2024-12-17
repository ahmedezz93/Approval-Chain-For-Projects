<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Table;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\NumberColumn;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\ProjectApprovalChain;

class ApprovalChainRelationManager extends RelationManager
{
    protected static string $relationship = 'approvalChain';

    protected static ?string $recordTitleAttribute = 'user.name';

    protected static ?string $inverseRelationship = 'project';

    public static function attach(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')
                ->label('Select User')
                ->relationship('user', 'name')
                ->options(function (RelationManager $livewire) {
                    return $livewire->ownerRecord->users->pluck('name', 'id');
                })
                ->multiple()
                ->required(),

            Forms\Components\Hidden::make('project_id')
                ->default(fn(RelationManager $livewire) => $livewire->ownerRecord->id),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('User Full Name')->sortable()->searchable(),
                TextColumn::make('status')->label('Status')->sortable()->searchable(),
                TextColumn::make('sequence')->label('Sequence')->sortable()->alignRight(),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->form(fn(Tables\Actions\AttachAction $action): array => [
                        Forms\Components\Select::make('user_ids')
                            ->label('Select Users')
                            ->options(
                                fn(RelationManager $livewire) =>
                                $livewire->ownerRecord->users->pluck('name', 'id')
                            )
                            ->multiple()
                            ->required(),

                        Forms\Components\Hidden::make('project_id')
                            ->default(fn(RelationManager $livewire) => $livewire->ownerRecord->id),
                    ])
                    ->action(function (array $data, RelationManager $livewire) {
                        $project = $livewire->ownerRecord;

                        try {
                            $project->updateApprovalChain($data['user_ids']);
                        } catch (\Exception $exception) {
                            Notification::make()
                                ->title('Error')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(function (RelationManager $livewire) {
                        $project = $livewire->ownerRecord;
                        $user = auth()->user();

                        return $user->can('Update project') &&
                            ($project->owner_id === $user->id ||
                                $project->users()
                                ->where('users.id', $user->id)
                                ->where('role', config('system.projects.affectations.roles.can_manage'))
                                ->exists());
                    }),
            ])

            ->actions([
                Action::make('approveAndForward')
                    ->label('Approve and Forward')
                    ->action(function (array $data, RelationManager $livewire) {
                        $project = $livewire->ownerRecord;
                        if (!$project || !$project->id) {
                            Notification::make()
                                ->title('Validation Error')
                                ->body('Project ID is missing or invalid.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $userId = auth()->id();
                        if (!$userId) {
                            Notification::make()
                                ->title('Validation Error')
                                ->body('User is not authenticated.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $requestData = [
                            'project_id' => $project->id,
                            'user_id' => $userId,
                        ];

                        $request = app(Request::class);
                        $request->merge($requestData);

                        try {
                            $response = $project->approveChains($request);
                            if ($response->status() === 200) {
                                $message = $response->getData()->message;
                                Notification::make()
                                    ->title('Approval successful')
                                    ->body($message)
                                    ->success()
                                    ->send();
                            } else {
                                $message = $response->getData()->message;
                                Notification::make()
                                    ->title('Approval failed')
                                    ->body($message)
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->button()
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn(ProjectApprovalChain $record) => $record->status === 'pending' && $record->user_id === auth()->id())
            ])
            ->defaultSort('sequence');
    }
}
