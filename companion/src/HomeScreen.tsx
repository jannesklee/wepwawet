import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  Alert,
  ActivityIndicator,
  Linking,
  ScrollView,
  StyleSheet,
  Switch,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import * as Location from 'expo-location';
import * as Clipboard from 'expo-clipboard';
import { type AppConfig } from './config';
import {
  fetchGroupInfo,
  fetchMembers,
  fetchShares,
  createShare,
  revokeShare,
  stopGuestSharing,
  type GroupInfo,
  type Member,
  type Share,
} from './api';
import { startSharing, stopSharing, isSharing } from './locationTask';

interface Props {
  config: AppConfig;
  onReconfigure: () => void;
}

const DURATIONS = [
  { minutes: 15, label: '15m' },
  { minutes: 60, label: '1h' },
  { minutes: 240, label: '4h' },
  { minutes: 0, label: '∞' },
] as const;

export default function HomeScreen({ config, onReconfigure }: Props) {
  const [sharing, setSharing] = useState(false);
  const [statusChecked, setStatusChecked] = useState(false);
  const [groupInfo, setGroupInfo] = useState<GroupInfo | null>(null);
  const [members, setMembers] = useState<Member[]>([]);
  const [shares, setShares] = useState<Share[]>([]);
  const [shareMinutes, setShareMinutes] = useState(60);
  const [creatingShare, setCreatingShare] = useState(false);
  const [copiedId, setCopiedId] = useState<number | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const groupInfoRef = useRef<GroupInfo | null>(null);

  // Keep ref in sync so the poll interval can access it without a stale closure
  groupInfoRef.current = groupInfo;

  const refreshMembers = useCallback(async () => {
    if (groupInfoRef.current) {
      const data = await fetchMembers(config, groupInfoRef.current.groupId);
      setMembers(data);
    }
  }, [config]);

  const refreshShares = useCallback(async () => {
    if (config.mode === 'nextcloud') {
      const data = await fetchShares(config);
      setShares(data);
    }
  }, [config]);

  useEffect(() => {
    isSharing().then((active) => {
      setSharing(active);
      setStatusChecked(true);
    });

    if (config.mode === 'nextcloud') {
      fetchGroupInfo(config).then((info) => {
        setGroupInfo(info);
        if (info) fetchMembers(config, info.groupId).then(setMembers);
      });
      fetchShares(config).then(setShares);
    }

    pollRef.current = setInterval(() => {
      refreshMembers();
      refreshShares();
    }, 15000);
    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
    };
  }, []);

  async function handleToggle() {
    if (sharing) {
      await stopSharing();
      if (config.mode === 'guest') stopGuestSharing(config);
      setSharing(false);
      return;
    }

    const { status: fg } =
      await Location.requestForegroundPermissionsAsync();
    if (fg !== 'granted') {
      Alert.alert(
        'Permission needed',
        'Location permission is required to share your position.',
      );
      return;
    }

    const { status: bg } =
      await Location.requestBackgroundPermissionsAsync();
    if (bg !== 'granted') {
      Alert.alert(
        'Background location needed',
        'Allow "Always" location access so LocShare keeps sharing when the screen is off.',
        [
          { text: 'Open Settings', onPress: () => Linking.openSettings() },
          { text: 'Cancel', style: 'cancel' },
        ],
      );
      return;
    }

    await startSharing();
    setSharing(true);
  }

  async function handleCreateShare() {
    setCreatingShare(true);
    const share = await createShare(config, shareMinutes);
    setCreatingShare(false);
    if (!share) return;
    setShares((prev) => [share, ...prev]);
    await Clipboard.setStringAsync(share.url);
    setCopiedId(share.id);
    setTimeout(() => setCopiedId(null), 2000);
  }

  async function handleCopy(share: Share) {
    await Clipboard.setStringAsync(share.url);
    setCopiedId(share.id);
    setTimeout(() => setCopiedId(null), 2000);
  }

  async function handleRevoke(share: Share) {
    const ok = await revokeShare(config, share.id);
    if (ok) setShares((prev) => prev.filter((s) => s.id !== share.id));
  }

  return (
    <SafeAreaView style={styles.root}>
      {/* Header */}
      <View style={styles.header}>
        <Text style={styles.headerTitle}>LocShare</Text>
        <TouchableOpacity
          style={styles.settingsBtn}
          onPress={() => {
            Alert.alert('Reconfigure', 'Clear current settings and set up again?', [
              {
                text: 'Yes, reconfigure',
                style: 'destructive',
                onPress: async () => {
                  await stopSharing();
                  onReconfigure();
                },
              },
              { text: 'Cancel', style: 'cancel' },
            ]);
          }}
        >
          <Text style={styles.settingsIcon}>⚙️</Text>
        </TouchableOpacity>
      </View>

      <ScrollView contentContainerStyle={styles.scroll}>
        {/* ── Sharing toggle ── */}
        <View style={styles.card}>
          <View style={styles.sharingRow}>
            <View
              style={[
                styles.statusDot,
                sharing ? styles.dotGreen : styles.dotGrey,
              ]}
            />
            <View style={styles.sharingTexts}>
              <Text style={styles.sharingTitle}>
                {sharing ? 'Sharing location' : 'Not sharing'}
              </Text>
              <Text style={styles.sharingSubtitle}>
                {sharing
                  ? 'Updating every ~5 s in background'
                  : 'Tap to start background sharing'}
              </Text>
            </View>
            {statusChecked ? (
              <Switch
                value={sharing}
                onValueChange={handleToggle}
                trackColor={{ true: PRIMARY, false: '#d1d5db' }}
                thumbColor="#fff"
              />
            ) : (
              <ActivityIndicator color={PRIMARY} />
            )}
          </View>
        </View>

        {/* ── Share links (authenticated only) ── */}
        {config.mode === 'nextcloud' && (
          <View style={styles.card}>
            <Text style={styles.sectionLabel}>Share my location</Text>

            <View style={styles.durationRow}>
              {DURATIONS.map(({ minutes, label }) => (
                <TouchableOpacity
                  key={minutes}
                  style={[
                    styles.durBtn,
                    shareMinutes === minutes && styles.durBtnActive,
                  ]}
                  onPress={() => setShareMinutes(minutes)}
                >
                  <Text
                    style={[
                      styles.durBtnText,
                      shareMinutes === minutes && styles.durBtnTextActive,
                    ]}
                  >
                    {label}
                  </Text>
                </TouchableOpacity>
              ))}
            </View>

            <TouchableOpacity
              style={[styles.createBtn, creatingShare && styles.createBtnDisabled]}
              onPress={handleCreateShare}
              disabled={creatingShare}
            >
              {creatingShare ? (
                <ActivityIndicator color="#fff" size="small" />
              ) : (
                <Text style={styles.createBtnText}>+ Create share link</Text>
              )}
            </TouchableOpacity>

            {shares.length > 0 && (
              <>
                <Text style={[styles.sectionLabel, { marginTop: 20 }]}>
                  Active links
                </Text>
                {shares.map((share) => (
                  <ShareRow
                    key={share.id}
                    share={share}
                    copied={copiedId === share.id}
                    onCopy={() => handleCopy(share)}
                    onRevoke={() => handleRevoke(share)}
                  />
                ))}
              </>
            )}
          </View>
        )}

        {/* ── Group members (authenticated only) ── */}
        {config.mode === 'nextcloud' && (
          <View style={styles.card}>
            <Text style={styles.sectionLabel}>Group members</Text>
            {members.length === 0 ? (
              <Text style={styles.emptyNote}>
                {groupInfo ? 'No positions yet' : 'Loading…'}
              </Text>
            ) : (
              members.map((m) => <MemberRow key={m.userId} member={m} />)
            )}
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

// ── ShareRow ─────────────────────────────────────────────────────────────────

function ShareRow({
  share,
  copied,
  onCopy,
  onRevoke,
}: {
  share: Share;
  copied: boolean;
  onCopy: () => void;
  onRevoke: () => void;
}) {
  return (
    <View style={styles.shareRow}>
      <View style={styles.shareInfo}>
        <Text style={styles.shareUrl} numberOfLines={1}>
          {share.url}
        </Text>
        <Text style={styles.shareExpiry}>{formatExpiry(share.expiresAt)}</Text>
      </View>
      <TouchableOpacity style={styles.iconBtn} onPress={onCopy}>
        <Text style={styles.iconText}>{copied ? '✓' : '📋'}</Text>
      </TouchableOpacity>
      <TouchableOpacity style={styles.iconBtn} onPress={onRevoke}>
        <Text style={styles.iconText}>🗑</Text>
      </TouchableOpacity>
    </View>
  );
}

// ── MemberRow ─────────────────────────────────────────────────────────────────

function MemberRow({ member }: { member: Member }) {
  const nowSec = Math.floor(Date.now() / 1000);
  const stale =
    member.updatedAt !== null && nowSec - member.updatedAt > 300;
  const dotColor = !member.hasPosition
    ? '#d1d5db'
    : stale
    ? '#f59e0b'
    : '#22c55e';

  return (
    <View style={styles.memberRow}>
      <View style={styles.avatar}>
        <Text style={styles.avatarText}>
          {member.displayName.charAt(0).toUpperCase()}
        </Text>
      </View>
      <View style={styles.memberInfo}>
        <Text style={styles.memberName}>
          {member.displayName}
          {member.isMe ? ' (you)' : ''}
        </Text>
        <Text style={styles.memberSeen}>
          {member.hasPosition
            ? formatLastSeen(member.updatedAt)
            : 'Not sharing'}
        </Text>
      </View>
      <View style={[styles.memberDot, { backgroundColor: dotColor }]} />
    </View>
  );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatExpiry(expiresAt: number | null): string {
  if (!expiresAt) return 'No expiry';
  const diff = expiresAt - Math.floor(Date.now() / 1000);
  if (diff <= 0) return 'Expired';
  const h = Math.floor(diff / 3600);
  const m = Math.floor((diff % 3600) / 60);
  return h > 0 ? `Expires in ${h}h ${m}m` : `Expires in ${m + 1} min`;
}

function formatLastSeen(ts: number | null): string {
  if (ts === null) return 'No position';
  const age = Math.floor(Date.now() / 1000) - ts;
  if (age < 60) return 'Just now';
  if (age < 3600) return `${Math.floor(age / 60)} min ago`;
  return `${Math.floor(age / 3600)}h ago`;
}

// ── Styles ────────────────────────────────────────────────────────────────────

const PRIMARY = '#0082c9';

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: '#f0f4f8' },

  header: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: PRIMARY,
    paddingHorizontal: 20,
    paddingVertical: 14,
  },
  headerTitle: { flex: 1, fontSize: 20, fontWeight: '700', color: '#fff' },
  settingsBtn: { padding: 4 },
  settingsIcon: { fontSize: 22 },

  scroll: { padding: 16, gap: 14, paddingBottom: 48 },

  card: {
    backgroundColor: '#fff',
    borderRadius: 14,
    padding: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.06,
    shadowRadius: 4,
    elevation: 2,
  },

  sharingRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  statusDot: { width: 12, height: 12, borderRadius: 6 },
  dotGreen: { backgroundColor: '#22c55e' },
  dotGrey: { backgroundColor: '#d1d5db' },
  sharingTexts: { flex: 1 },
  sharingTitle: { fontSize: 16, fontWeight: '600', color: '#111' },
  sharingSubtitle: { fontSize: 13, color: '#6b7280', marginTop: 2 },

  sectionLabel: {
    fontSize: 11,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 0.7,
    color: '#9ca3af',
    marginBottom: 12,
  },

  durationRow: { flexDirection: 'row', gap: 8, marginBottom: 12 },
  durBtn: {
    flex: 1,
    paddingVertical: 9,
    borderRadius: 8,
    borderWidth: 2,
    borderColor: '#d1d5db',
    alignItems: 'center',
    backgroundColor: '#fafafa',
  },
  durBtnActive: { borderColor: PRIMARY, backgroundColor: PRIMARY },
  durBtnText: { fontSize: 13, fontWeight: '600', color: '#4b5563' },
  durBtnTextActive: { color: '#fff' },

  createBtn: {
    backgroundColor: PRIMARY,
    borderRadius: 10,
    paddingVertical: 13,
    alignItems: 'center',
  },
  createBtnDisabled: { backgroundColor: '#9ca3af' },
  createBtnText: { color: '#fff', fontWeight: '600', fontSize: 15 },

  shareRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 4,
    gap: 4,
  },
  shareInfo: { flex: 1, minWidth: 0 },
  shareUrl: { fontSize: 13, color: PRIMARY, fontWeight: '500' },
  shareExpiry: { fontSize: 11, color: '#9ca3af', marginTop: 2 },
  iconBtn: { padding: 8 },
  iconText: { fontSize: 18 },

  memberRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingVertical: 6,
  },
  avatar: {
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: PRIMARY,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: { color: '#fff', fontWeight: '700', fontSize: 15 },
  memberInfo: { flex: 1 },
  memberName: { fontSize: 14, fontWeight: '500', color: '#111' },
  memberSeen: { fontSize: 12, color: '#9ca3af', marginTop: 1 },
  memberDot: { width: 8, height: 8, borderRadius: 4 },

  emptyNote: { fontSize: 14, color: '#9ca3af', textAlign: 'center', paddingVertical: 8 },
});
