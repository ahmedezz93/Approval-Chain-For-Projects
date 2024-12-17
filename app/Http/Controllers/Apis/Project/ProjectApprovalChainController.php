<?php

namespace App\Http\Controllers\Apis\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectApprovalChain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProjectApprovalChainController extends Controller
{
    public function updateOrCreateChain(Request $request, $projectId)
    {
        try {

            $project = Project::find($projectId);
            if (!$project) {
                return response()->json([
                    'success' => false,
                    "message" => "Project Not Found"
                ], 404);
            }

            $this->authorize('update', $project);

            $validated = Validator::make($request->all(), [
                'users' => 'required|array',
                'users.*' => "distinct|exists:project_users,user_id,project_id,{$project->id}",
            ])->validate();

            $users = $validated['users'];

            DB::beginTransaction();
            $currentUsers = $project->approvalChain()->pluck('user_id')->toArray();
            $usersToRemove = array_diff($currentUsers, $users);

            if (!empty($usersToRemove)) {
                $project->approvalChain()->whereIn('user_id', $usersToRemove)->forceDelete();
            }

            collect($users)->map(function ($userId, $index) use ($project) {
                $project->approvalChain()->updateOrCreate(
                    ['user_id' => $userId, 'project_id' => $project->id],
                    ['user_id' => $userId, 'project_id' => $project->id, 'sequence' => $index + 1]
                );
            });

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Approval chain created successfully.',
            ], 200);
        } catch (ValidationException $exception) {

            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $exception->errors(),
            ], 422);
        } catch (\Exception $exception) {

            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $exception->getMessage(),
            ], 400);
        }
    }


    public function approveChain($projectId)
    {

        try {

            $project = Project::find($projectId);
            if (!$project) {
                return response()->json([
                    'success' => false,
                    "message" => "Project Not Found"
                ], 404);
            }

            $userId = auth()->user()->id;
            $approvalChain = $project->approvalChain()
                ->where('user_id', $userId)
                ->orderBy('sequence')
                ->first();
            if (!$approvalChain) {
                return response()->json([
                    'success' => false,
                    'error' => 'User is not part of the approval chain'
                ], 403);
            }
            $currentApprover = $project->approvalChain()
                ->where('status', 'Pending')
                ->orderBy('sequence')
                ->first();

            if (!$currentApprover) {
                return response()->json([
                    'success' => false,
                    'message' => 'All approvals are already completed'
                ], 400);
            }

            if ($currentApprover->user_id != $userId) {
                return response()->json([
                    'success' => false,
                    'error' => 'User is not the current approver'
                ], 403);
            }
            DB::beginTransaction();
            $currentApprover->update([
                'status' => 'approved',
            ]);
            if ($currentApprover->sequence == $project->approvalChain()->count()) {
                $project->completedStatus();
                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'Project marked as Completed'
                ], 200);
            }
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Project approved and forwarded to the next user'
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve project.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getProjectWithApprovalChain($projectId)
    {
        $project = Project::with('approvalChain.user')->find($projectId);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found.',
            ], 404);
        }
        $this->authorize('view', $project);

        $approvalChain = $project->approvalChain()
            ->orderBy('sequence')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Show Project With Approval Chain Successfully.',
            'project' => $project->name,
            'approval_chain' => $approvalChain->map(function ($chain) {
                return [
                    'user' => $chain->user->name,
                    'status' => $chain->status,
                    'created_at' => $chain->created_at ? $chain->created_at->format('Y-m-d') : null,
                    'updated_at' => $chain->updated_at ? $chain->updated_at->format('Y-m-d') : null,
                ];
            }),
        ], 200);
    }
}
