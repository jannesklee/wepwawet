import React, { useState } from 'react';
import {
  View,
  Text,
  TextInput,
  TouchableOpacity,
  ScrollView,
  StyleSheet,
  KeyboardAvoidingView,
  Platform,
  ActivityIndicator,
  Alert,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  saveConfig,
  parseInviteUrl,
  normalizeUrl,
  type AppConfig,
  type AuthMode,
} from './config';
import { fetchGroups } from './api';

interface Props {
  onSaved: (config: AppConfig) => void;
  initialConfig?: AppConfig | null;
  onCancel?: () => void;
}

const DURATIONS = [
  { minutes: 15, label: '15m' },
  { minutes: 60, label: '1h' },
  { minutes: 240, label: '4h' },
  { minutes: 0, label: '∞' },
] as const;

export default function SetupScreen({ onSaved, initialConfig, onCancel }: Props) {
  const [mode, setMode] = useState<AuthMode>(initialConfig?.mode ?? 'nextcloud');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Nextcloud fields
  const [serverUrl, setServerUrl] = useState(
    initialConfig?.mode === 'nextcloud' ? initialConfig.serverUrl : '',
  );
  const [username, setUsername] = useState(initialConfig?.username ?? '');
  const [appPassword, setAppPassword] = useState(initialConfig?.appPassword ?? '');
  const [showPassword, setShowPassword] = useState(false);

  // Guest fields - this form only creates the first joined group; once set
  // up, additional invite links are added from the Home screen so existing
  // groups aren't lost.
  const [inviteUrl, setInviteUrl] = useState('');
  const [guestName, setGuestName] = useState('');
  const [guestDuration, setGuestDuration] = useState(60);

  const canSave =
    mode === 'nextcloud'
      ? !!(serverUrl.trim() && username.trim() && appPassword.trim())
      : !!(inviteUrl.trim() && guestName.trim());

  async function handleSave() {
    setSaving(true);
    setError(null);
    try {
      if (mode === 'nextcloud') {
        const candidate: AppConfig = {
          mode: 'nextcloud',
          serverUrl: normalizeUrl(serverUrl),
          username: username.trim(),
          appPassword: appPassword.trim(),
          guestLinks: [],
          isValid: true,
        };
        const resp = await fetchGroups(candidate);
        if (!resp) {
          setError(
            "Couldn't connect. Check the Server URL, username, and app password.",
          );
          return;
        }
        const config = await saveConfig(candidate);
        onSaved(config);
      } else {
        const parsed = parseInviteUrl(inviteUrl);
        if (!parsed) {
          Alert.alert(
            'Invalid URL',
            'Could not parse the invite link. Paste the full URL you received.',
          );
          return;
        }
        const config = await saveConfig({
          mode: 'guest',
          serverUrl: parsed.server,
          username: '',
          appPassword: '',
          guestLinks: [
            {
              token: parsed.token,
              server: parsed.server,
              name: guestName.trim(),
              duration: guestDuration,
              enabled: true,
            },
          ],
        });
        onSaved(config);
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <SafeAreaView style={styles.root}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
      >
        <ScrollView
          contentContainerStyle={styles.scroll}
          keyboardShouldPersistTaps="handled"
        >
          <View style={styles.header}>
            {onCancel && (
              <TouchableOpacity style={styles.cancelBtn} onPress={onCancel}>
                <Text style={styles.cancelBtnText}>Cancel</Text>
              </TouchableOpacity>
            )}
            <Text style={styles.title}>Sopdet</Text>
            <Text style={styles.subtitle}>Connect to your Nextcloud</Text>
          </View>

          {/* Mode selector */}
          <View style={styles.tabs}>
            {(['nextcloud', 'guest'] as AuthMode[]).map((m) => (
              <TouchableOpacity
                key={m}
                style={[styles.tab, mode === m && styles.tabActive]}
                onPress={() => setMode(m)}
              >
                <Text style={[styles.tabText, mode === m && styles.tabTextActive]}>
                  {m === 'nextcloud' ? 'Nextcloud login' : 'Invite link'}
                </Text>
              </TouchableOpacity>
            ))}
          </View>

          {mode === 'nextcloud' ? (
            <View style={styles.form}>
              <Field label="Server URL" hint="https://cloud.example.com">
                <TextInput
                  style={styles.input}
                  value={serverUrl}
                  onChangeText={setServerUrl}
                  placeholder="https://cloud.example.com"
                  placeholderTextColor="#aaa"
                  autoCapitalize="none"
                  autoCorrect={false}
                  keyboardType="url"
                />
                {__DEV__ && (
                  <Text style={styles.devHint}>
                    Testing from the Android emulator? Use 10.0.2.2, not localhost — the emulator can't reach the host machine's own localhost.
                  </Text>
                )}
              </Field>
              <Field label="Username">
                <TextInput
                  style={styles.input}
                  value={username}
                  onChangeText={setUsername}
                  placeholder="your.username"
                  placeholderTextColor="#aaa"
                  autoCapitalize="none"
                  autoCorrect={false}
                  textContentType="username"
                />
              </Field>
              <Field
                label="App password"
                hint="Nextcloud → Settings → Security → App passwords"
              >
                <View style={styles.passwordRow}>
                  <TextInput
                    style={[styles.input, styles.passwordInput]}
                    value={appPassword}
                    onChangeText={setAppPassword}
                    placeholder="xxxx-xxxx-xxxx-xxxx"
                    placeholderTextColor="#aaa"
                    autoCapitalize="none"
                    autoCorrect={false}
                    secureTextEntry={!showPassword}
                    textContentType="password"
                  />
                  <TouchableOpacity
                    style={styles.eyeBtn}
                    onPress={() => setShowPassword((v) => !v)}
                  >
                    <Text style={styles.eyeIcon}>{showPassword ? '🙈' : '👁️'}</Text>
                  </TouchableOpacity>
                </View>
              </Field>
            </View>
          ) : (
            <View style={styles.form}>
              <Field label="Invite URL" hint="Paste the full join link you received">
                <TextInput
                  style={[styles.input, styles.multilineInput]}
                  value={inviteUrl}
                  onChangeText={setInviteUrl}
                  placeholder="https://cloud.example.com/apps/sopdet/join/…"
                  placeholderTextColor="#aaa"
                  autoCapitalize="none"
                  autoCorrect={false}
                  keyboardType="url"
                  multiline
                />
              </Field>
              <Field label="Your display name">
                <TextInput
                  style={styles.input}
                  value={guestName}
                  onChangeText={setGuestName}
                  placeholder="Jane"
                  placeholderTextColor="#aaa"
                />
              </Field>
              <Field label="Share for">
                <View style={styles.durationRow}>
                  {DURATIONS.map(({ minutes, label }) => (
                    <TouchableOpacity
                      key={minutes}
                      style={[
                        styles.durBtn,
                        guestDuration === minutes && styles.durBtnActive,
                      ]}
                      onPress={() => setGuestDuration(minutes)}
                    >
                      <Text
                        style={[
                          styles.durBtnText,
                          guestDuration === minutes && styles.durBtnTextActive,
                        ]}
                      >
                        {label}
                      </Text>
                    </TouchableOpacity>
                  ))}
                </View>
              </Field>
            </View>
          )}

          {error && <Text style={styles.errorText}>{error}</Text>}

          <TouchableOpacity
            style={[styles.saveBtn, (!canSave || saving) && styles.saveBtnDisabled]}
            onPress={handleSave}
            disabled={!canSave || saving}
          >
            {saving ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.saveBtnText}>Save & start sharing</Text>
            )}
          </TouchableOpacity>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function Field({
  label,
  hint,
  children,
}: {
  label: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      {children}
      {hint ? <Text style={styles.fieldHint}>{hint}</Text> : null}
    </View>
  );
}

const PRIMARY = '#0082c9';

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: '#f5f7fa' },
  flex: { flex: 1 },
  scroll: { padding: 24, paddingBottom: 48 },

  header: { alignItems: 'center', marginBottom: 32, position: 'relative' },
  cancelBtn: { position: 'absolute', top: 2, right: 0, padding: 6 },
  cancelBtnText: { fontSize: 15, color: '#6b7280', fontWeight: '500' },
  title: { fontSize: 32, fontWeight: '700', color: PRIMARY, letterSpacing: -0.5 },
  subtitle: { fontSize: 15, color: '#767676', marginTop: 4 },

  tabs: {
    flexDirection: 'row',
    backgroundColor: '#e5e7eb',
    borderRadius: 10,
    padding: 3,
    marginBottom: 28,
  },
  tab: {
    flex: 1,
    paddingVertical: 9,
    borderRadius: 8,
    alignItems: 'center',
  },
  tabActive: {
    backgroundColor: '#fff',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.1,
    shadowRadius: 2,
    elevation: 2,
  },
  tabText: { fontSize: 14, color: '#6b7280', fontWeight: '500' },
  tabTextActive: { color: '#111', fontWeight: '600' },

  form: { gap: 18 },
  field: { gap: 5 },
  fieldLabel: { fontSize: 13, fontWeight: '600', color: '#374151' },
  fieldHint: { fontSize: 12, color: '#9ca3af', marginTop: 3 },
  devHint: { fontSize: 12, color: '#b45309', marginTop: 5 },

  errorText: {
    fontSize: 13,
    color: '#dc2626',
    marginTop: 20,
    textAlign: 'center',
  },

  input: {
    backgroundColor: '#fff',
    borderWidth: 1,
    borderColor: '#d1d5db',
    borderRadius: 8,
    paddingHorizontal: 13,
    paddingVertical: 11,
    fontSize: 15,
    color: '#111',
  },
  multilineInput: {
    minHeight: 60,
    textAlignVertical: 'top',
  },
  passwordRow: { flexDirection: 'row' },
  passwordInput: {
    flex: 1,
    borderTopRightRadius: 0,
    borderBottomRightRadius: 0,
  },
  eyeBtn: {
    backgroundColor: '#fff',
    borderWidth: 1,
    borderColor: '#d1d5db',
    borderLeftWidth: 0,
    borderTopRightRadius: 8,
    borderBottomRightRadius: 8,
    paddingHorizontal: 12,
    justifyContent: 'center',
  },
  eyeIcon: { fontSize: 18 },

  durationRow: { flexDirection: 'row', gap: 8 },
  durBtn: {
    flex: 1,
    paddingVertical: 10,
    borderRadius: 8,
    borderWidth: 2,
    borderColor: '#d1d5db',
    alignItems: 'center',
    backgroundColor: '#fff',
  },
  durBtnActive: { borderColor: PRIMARY, backgroundColor: PRIMARY },
  durBtnText: { fontSize: 14, fontWeight: '600', color: '#4b5563' },
  durBtnTextActive: { color: '#fff' },

  saveBtn: {
    backgroundColor: PRIMARY,
    borderRadius: 12,
    paddingVertical: 15,
    alignItems: 'center',
    marginTop: 36,
  },
  saveBtnDisabled: { backgroundColor: '#9ca3af' },
  saveBtnText: { color: '#fff', fontSize: 16, fontWeight: '700' },
});
