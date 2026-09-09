import React, { useEffect, useMemo, useRef } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Camera, type CameraRef, Map, Marker } from '@maplibre/maplibre-react-native';
import type { StyleSpecification } from '@maplibre/maplibre-gl-style-spec';
import type { Member } from './api';

// Same raw-tile OSM style as the web app (src/App.vue / src/Viewer.vue) -
// no API key, no third-party service, direct to tile.openstreetmap.org.
const MAP_STYLE: StyleSpecification = {
  version: 8,
  sources: {
    osm: {
      type: 'raster',
      tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
      tileSize: 256,
      attribution: '© OpenStreetMap contributors',
    },
  },
  layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
};

const PRIMARY = '#0082c9';

interface Props {
  members: Member[];
}

export default function GroupMap({ members }: Props) {
  const cameraRef = useRef<CameraRef>(null);

  const positioned = useMemo(
    () => members.filter((m) => m.hasPosition && m.lat !== null && m.lon !== null),
    [members],
  );

  const boundsKey = positioned.map((m) => `${m.userId}:${m.lat},${m.lon}`).join('|');

  useEffect(() => {
    if (!cameraRef.current || positioned.length === 0) return;

    if (positioned.length === 1) {
      cameraRef.current.flyTo({
        center: [positioned[0].lon as number, positioned[0].lat as number],
        zoom: 13,
        duration: 500,
      });
      return;
    }

    const lons = positioned.map((m) => m.lon as number);
    const lats = positioned.map((m) => m.lat as number);
    cameraRef.current.fitBounds(
      [Math.min(...lons), Math.min(...lats), Math.max(...lons), Math.max(...lats)],
      { padding: { top: 40, right: 40, bottom: 40, left: 40 }, duration: 500 },
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [boundsKey]);

  if (positioned.length === 0) {
    return (
      <View style={[styles.map, styles.empty]}>
        <Text style={styles.emptyText}>No positions to show yet</Text>
      </View>
    );
  }

  return (
    <View style={styles.map}>
      <Map style={styles.map} mapStyle={MAP_STYLE} logo={false} attribution={false}>
        <Camera
          ref={cameraRef}
          initialViewState={{
            center: [positioned[0].lon as number, positioned[0].lat as number],
            zoom: 13,
          }}
        />
        {positioned.map((m) => (
          <Marker key={m.userId} id={m.userId} lngLat={[m.lon as number, m.lat as number]}>
            <View style={[styles.marker, m.isMe && styles.markerMe]}>
              <Text style={styles.markerText}>{m.displayName.charAt(0).toUpperCase()}</Text>
            </View>
          </Marker>
        ))}
      </Map>
    </View>
  );
}

const styles = StyleSheet.create({
  map: { width: '100%', height: 220, borderRadius: 14, overflow: 'hidden' },
  empty: {
    backgroundColor: '#f0f4f8',
    alignItems: 'center',
    justifyContent: 'center',
  },
  emptyText: { fontSize: 13, color: '#9ca3af' },
  marker: {
    width: 30,
    height: 30,
    borderRadius: 15,
    borderWidth: 2,
    borderColor: '#fff',
    backgroundColor: PRIMARY,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.3,
    shadowRadius: 2,
    elevation: 3,
  },
  markerMe: { borderColor: PRIMARY, borderWidth: 3 },
  markerText: { color: '#fff', fontWeight: '700', fontSize: 13 },
});
