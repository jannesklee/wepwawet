// Import the task definition before any React rendering so expo-task-manager
// can register 'locshare-background-location' at module load time.
import './src/locationTask';

import React, { useEffect, useState } from 'react';
import { ActivityIndicator, View } from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { loadConfig, type AppConfig } from './src/config';
import SetupScreen from './src/SetupScreen';
import HomeScreen from './src/HomeScreen';

export default function App() {
  const [config, setConfig] = useState<AppConfig | null>(null);
  const [loading, setLoading] = useState(true);
  const [reconfiguring, setReconfiguring] = useState(false);

  useEffect(() => {
    loadConfig().then((c) => {
      setConfig(c);
      setLoading(false);
    });
  }, []);

  if (loading) {
    return (
      <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#fff' }}>
        <ActivityIndicator size="large" color="#0082c9" />
      </View>
    );
  }

  return (
    <SafeAreaProvider>
      <StatusBar style="light" />
      {config?.isValid && !reconfiguring ? (
        <HomeScreen config={config} onReconfigure={() => setReconfiguring(true)} />
      ) : (
        <SetupScreen
          initialConfig={config}
          onSaved={(c) => {
            setConfig(c);
            setReconfiguring(false);
          }}
          onCancel={config?.isValid ? () => setReconfiguring(false) : undefined}
        />
      )}
    </SafeAreaProvider>
  );
}
