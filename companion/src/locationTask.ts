import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';
import * as SecureStore from 'expo-secure-store';
import { sendPosition, sendGuestPosition } from './api';
import { type AppConfig, normalizeConfig } from './config';

export const LOCATION_TASK = 'wepwawet-background-location';

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
      console.error('[Wepwawet] Location task error:', error.message);
      return;
    }
    const { locations } = data;
    if (!locations?.length) return;

    const loc = locations[locations.length - 1];

    try {
      const raw = await SecureStore.getItemAsync('wepwawet_config');
      if (!raw) return;
      const config = normalizeConfig(JSON.parse(raw) as Omit<AppConfig, 'isValid'>);
      if (!config.isValid) return;

      const lat = loc.coords.latitude;
      const lon = loc.coords.longitude;
      const acc = loc.coords.accuracy ?? null;
      const alt = loc.coords.altitude ?? null;
      const speed = loc.coords.speed ?? null;
      const heading = loc.coords.heading ?? null;

      if (config.mode === 'nextcloud') {
        await sendPosition(config, lat, lon, acc, alt, speed, heading);
      } else {
        await Promise.all(
          config.guestLinks
            .filter((link) => link.enabled)
            .map((link) => sendGuestPosition(link, lat, lon, acc, alt, speed, heading)),
        );
      }
    } catch (e) {
      console.error('[Wepwawet] Failed to send position:', e);
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
      notificationTitle: 'Wepwawet',
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
