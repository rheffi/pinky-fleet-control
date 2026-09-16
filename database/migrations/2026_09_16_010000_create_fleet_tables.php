<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_state', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
            $table->uuid('active_run_id')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
        });
        Schema::create('robots', function (Blueprint $table) {
            $table->string('id', 16)->primary();
            $table->string('label');
            $table->unsignedInteger('ros_domain_id');
            $table->string('motion_state')->default('idle');
            $table->json('pose')->nullable();
            $table->timestamp('received_at')->nullable();
        });
        Schema::create('runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mode');
            $table->string('map_id');
            $table->string('map_version');
            $table->string('status')->index();
            $table->json('error')->nullable();
            $table->unsignedInteger('tick')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('run_robots', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('runs');
            $table->string('robot_id', 16);
            $table->foreign('robot_id')->references('id')->on('robots');
            $table->string('goal_id');
            $table->json('goal_pose');
            $table->json('planned_path');
            $table->string('path_source')->default('fixture');
            $table->string('state')->default('pending');
            $table->timestamp('stop_ack_at')->nullable();
            $table->json('result')->nullable();
            $table->unique(['run_id', 'robot_id']);
        });
        Schema::create('commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->unique();
            $table->foreignUuid('run_id')->constrained('runs');
            $table->string('type');
            $table->string('payload_hash', 64);
            $table->string('status');
            $table->timestamp('created_at');
        });
        Schema::create('run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('runs');
            $table->string('robot_id', 16)->nullable();
            $table->string('type');
            $table->string('message');
            $table->string('mode')->default('sample');
            $table->timestamp('occurred_at');
            $table->index(['run_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['run_events', 'commands', 'run_robots', 'runs', 'robots', 'fleet_state'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
