<script setup>
import EnvironmentView from './EnvironmentView.vue';
import FleetDashboard from './FleetDashboard.vue';
const environment = window.location.pathname === '/environment';
</script>

<template>
    <EnvironmentView v-if="environment" />
    <FleetDashboard v-else />
</template>
