import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';
import * as SecureStore from 'expo-secure-store';
import { sendPosition } from './api';
import { type AppConfig } from './config';

export const LOCATION_TASK = 'locshare-background-location';

interface LocationTaskData {
  locations: Location.LocationObject[];
}

// Must be defined at module level (top-level call, outside React components).
// This file is imported in App.tsx before any rendering.
TaskManager.defineTask(
  LOCATION_TASK,
  async ({
    data,
    error,
  }: TaskManager.TaskManagerTaskBody<LocationTaskData>) => {
    if (error) {
      console.error('[LocShare] Location task error:', error.message);
      return;
    }
    const { locations } = data;
    if (!locations?.length) return;

    const loc = locations[locations.length - 1];

    try {
      const raw = await SecureStore.getItemAsync('locshare_config');
      if (!raw) return;
      const config = JSON.parse(raw) as AppConfig;
      if (!config.isValid) return;

      await sendPosition(
        config,
        loc.coords.latitude,
        loc.coords.longitude,
        loc.coords.accuracy ?? null,
        loc.coords.altitude ?? null,
        loc.coords.speed ?? null,
        loc.coords.heading ?? null,
      );
    } catch (e) {
      console.error('[LocShare] Failed to send position:', e);
    }
  },
);

export async function startSharing(): Promise<void> {
  await Location.startLocationUpdatesAsync(LOCATION_TASK, {
    accuracy: Location.Accuracy.High,
    timeInterval: 5000,   // aim for 5-second updates
    distanceInterval: 0,  // update regardless of movement
    pausesUpdatesAutomatically: false,
    activityType: Location.ActivityType.Other,
    showsBackgroundLocationIndicator: true, // iOS blue pill
    foregroundService: {
      notificationTitle: 'LocShare',
      notificationBody: 'Sharing your location…',
      notificationColor: '#0082c9',
    },
  });
}

export async function stopSharing(): Promise<void> {
  const running = await Location.hasStartedLocationUpdatesAsync(LOCATION_TASK);
  if (running) {
    await Location.stopLocationUpdatesAsync(LOCATION_TASK);
  }
}

export function isSharing(): Promise<boolean> {
  return Location.hasStartedLocationUpdatesAsync(LOCATION_TASK);
}
