// Import the task definition before any React rendering so expo-task-manager
// can register 'locshare-background-location' at module load time.
import './src/locationTask';

import React, { useEffect, useState } from 'react';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { loadConfig, type AppConfig } from './src/config';
import SetupScreen from './src/SetupScreen';
import HomeScreen from './src/HomeScreen';

export default function App() {
  const [config, setConfig] = useState<AppConfig | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadConfig().then((c) => {
      setConfig(c);
      setLoading(false);
    });
  }, []);

  if (loading) return null;

  return (
    <SafeAreaProvider>
      <StatusBar style="light" />
      {config?.isValid ? (
        <HomeScreen config={config} onReconfigure={() => setConfig(null)} />
      ) : (
        <SetupScreen onSaved={setConfig} />
      )}
    </SafeAreaProvider>
  );
}
