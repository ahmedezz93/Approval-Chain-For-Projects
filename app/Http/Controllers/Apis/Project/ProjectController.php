<?php

namespace App\Http\Controllers\Apis\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * List users associated with a project.
     *
     * @param Project $project The project model instance.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function listProjectUsers(Project $project)
    {
        // Retrieve users with only the required fields
        $users = $project->users()->select('users.id', 'users.name')->get();

        // Return the list as a JSON response
        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }
}
