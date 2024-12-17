<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Project extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'name', 'description', 'status_id', 'owner_id', 'ticket_prefix',
        'status_type', 'type'
    ];

    protected $appends = [
        'cover'
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id', 'id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id', 'id')->withTrashed();
    }

    public function completedStatus()
    {
        $completedStatus = ProjectStatus::query()
            ->where('is_default', 1)
            ->withTrashed()
            ->first();

        if ($completedStatus) {
            $this->status_id = $completedStatus->id;
            $this->save();
        }
    }
        public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_users', 'project_id', 'user_id')->withPivot(['role']);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'project_id', 'id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(TicketStatus::class, 'project_id', 'id');
    }

    public function epics(): HasMany
    {
        return $this->hasMany(Epic::class, 'project_id', 'id');
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class, 'project_id', 'id');
    }

    public function epicsFirstDate(): Attribute
    {
        return new Attribute(
            get: function () {
                $firstEpic = $this->epics()->orderBy('starts_at')->first();
                if ($firstEpic) {
                    return $firstEpic->starts_at;
                }
                return now();
            }
        );
    }

    public function epicsLastDate(): Attribute
    {
        return new Attribute(
            get: function () {
                $firstEpic = $this->epics()->orderBy('ends_at', 'desc')->first();
                if ($firstEpic) {
                    return $firstEpic->ends_at;
                }
                return now();
            }
        );
    }

    public function contributors(): Attribute
    {
        return new Attribute(
            get: function () {
                $users = $this->users;
                $users->push($this->owner);
                return $users->unique('id');
            }
        );
    }

    public function cover(): Attribute
    {
        return new Attribute(
            get: fn() => $this->media('cover')?->first()?->getFullUrl()
                ??
                'https://ui-avatars.com/api/?background=3f84f3&color=ffffff&name=' . $this->name
        );
    }

    public function currentSprint(): Attribute
    {
        return new Attribute(
            get: fn() => $this->sprints()
                ->whereNotNull('started_at')
                ->whereNull('ended_at')
                ->first()
        );
    }

    public function nextSprint(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->currentSprint) {
                    return $this->sprints()
                        ->whereNull('started_at')
                        ->whereNull('ended_at')
                        ->where('starts_at', '>=', $this->currentSprint->ends_at)
                        ->orderBy('starts_at')
                        ->first();
                }
                return null;
            }
        );
    }

    public function approvalChain()
    {
        return $this->hasMany(ProjectApprovalChain::class, 'project_id', 'id')->withTrashed();
    }



    public function updateApprovalChain(array $userIds)
    {
        try {
            $validated = Validator::make(['user_ids' => $userIds], [
                'user_ids' => 'required|array',
                'user_ids.*' => "distinct|exists:project_users,user_id,project_id,{$this->id}",
            ])->validate();

            DB::beginTransaction();

            $currentUsers = $this->approvalChain()->pluck('user_id')->toArray();

            $usersToRemove = array_diff($currentUsers, $userIds);

            if (!empty($usersToRemove)) {
                $this->approvalChain()->whereIn('user_id', $usersToRemove)->forceDelete();
            }

            foreach ($userIds as $index => $userId) {
                $this->approvalChain()->updateOrCreate(
                    ['user_id' => $userId, 'project_id' => $this->id],
                    ['sequence' => $index + 1]
                );
            }

            DB::commit();

            Notification::make()
                ->title('Approval chain updated successfully.')
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            DB::rollback();
            Notification::make()
                ->title('Validation failed.')
                ->body(implode(', ', $exception->errors()))
                ->danger()
                ->send();
        } catch (\Exception $exception) {
            DB::rollback();
            Notification::make()
                ->title('Something went wrong.')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }


    public function approveChains(Request $request)
    {
        // إجراء التحقق من المدخلات
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:project_approval_chains,project_id',
            'user_id' => 'required|exists:project_approval_chains,user_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $validated = $validator->validated();

            $project = Project::findOrFail($validated['project_id']);
            $approvalChain = $project->approvalChain()
                ->where('user_id', $validated['user_id'])
                ->orderBy('sequence')
                ->first();

            if (!$approvalChain) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not part of the approval chain',
                ], 403);
            }

            $currentApprover = $project->approvalChain()
                ->where('status', 'Pending')
                ->orderBy('sequence')
                ->first();

            if (!$currentApprover) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'All approvals are already completed',
                ], 400);
            }

            if ($currentApprover->user_id != $validated['user_id']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not the current approver',
                ], 403);
            }

            $currentApprover->update(['status' => 'approved']);

            if ($currentApprover->sequence == $project->approvalChain()->count()) {
                $project->completedStatus();
                return response()->json([
                    'status' => 'success',
                    'message' => 'Project marked as Completed',
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Project approved and forwarded to the next user',
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Project not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve project.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


}


