<?php

namespace App\Http\Controllers;

use App\Fleet\FleetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FleetAgentController extends Controller
{
    public function __construct(private FleetService $fleet) {}

    public function command(string $robot)
    {
        return response()->json($this->fleet->commandFor($robot));
    }

    public function telemetry(Request $request, string $robot)
    {
        $data = $request->validate([
            'run_id' => ['nullable', 'uuid'],
            'connected' => ['required', 'boolean'],
            'motion_state' => ['required', Rule::in(['idle', 'moving', 'arrived', 'failed', 'stopped', 'unknown'])],
            'action_result' => ['nullable', Rule::in(['succeeded', 'aborted', 'rejected', 'failed', 'canceled'])],
            'message' => ['nullable', 'string', 'max:500'],
            'pose' => ['nullable', 'array:map_id,map_version,frame_id,x_m,y_m,yaw_rad'],
            'pose.map_id' => ['required_with:pose', 'string', 'max:100'],
            'pose.map_version' => ['required_with:pose', 'string', 'max:40'],
            'pose.frame_id' => ['required_with:pose', 'string', 'max:100'],
            'pose.x_m' => ['required_with:pose', 'numeric'],
            'pose.y_m' => ['required_with:pose', 'numeric'],
            'pose.yaw_rad' => ['required_with:pose', 'numeric'],
        ]);

        return response()->json($this->fleet->telemetry($robot, $data));
    }
}
