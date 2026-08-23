import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  Alert,
  ActivityIndicator,
  Linking,
  ScrollView,
  StyleSheet,
  Switch,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import * as Location from 'expo-location';
import * as Clipboard from 'expo-clipboard';
import { type AppConfig, parseInviteUrl } from './config';
import {
  fetchGroups,
  fetchMembers,
  fetchShares,
  createGroup,
  joinGroup,
  setGroupVisibility,
  removeGroupMember,
  deleteGroup,
  createShare,
  revokeShare,
  stopGuestSharing,
  type Group,
  type Member,
  type Share,
} from './api';
import { startSharing, stopSharing, isSharing } from './locationTask';
import { loadNicknames, setNickname, type Nicknames } from './nicknames';

interface Props {
  config: AppConfig;
  onReconfigure: () => void;
}

interface GroupWithMembers extends Group {
  members: Member[];
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
  const [groups, setGroups] = useState<GroupWithMembers[]>([]);
  const [groupsFailed, setGroupsFailed] = useState(false);
  const [openGroupId, setOpenGroupId] = useState<number | null>(null);
  const [newGroupName, setNewGroupName] = useState('');
  const [creatingGroup, setCreatingGroup] = useState(false);
  const [copiedGroupId, setCopiedGroupId] = useState<number | null>(null);
  const [joinInviteUrl, setJoinInviteUrl] = useState('');
  const [joiningGroup, setJoiningGroup] = useState(false);
  const [nicknames, setNicknames] = useState<Nicknames>({});
  const [shares, setShares] = useState<Share[]>([]);
  const [shareMinutes, setShareMinutes] = useState(60);
  const [creatingShare, setCreatingShare] = useState(false);
  const [copiedId, setCopiedId] = useState<number | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const groupsRef = useRef<GroupWithMembers[]>([]);

  // Keep ref in sync so the poll interval can access it without a stale closure
  groupsRef.current = groups;

  const loadGroups = useCallback(async () => {
    const resp = await fetchGroups(config);
    if (!resp) {
      if (groupsRef.current.length === 0) setGroupsFailed(true);
      return;
    }
    setGroupsFailed(false);
    const withMembers = await Promise.all(
      resp.groups.map(async (g) => ({ ...g, members: await fetchMembers(config, g.id) })),
    );
    setGroups(withMembers);
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
      loadGroups();
      fetchShares(config).then(setShares);
      loadNicknames().then(setNicknames);
    }

    pollRef.current = setInterval(() => {
      if (config.mode === 'nextcloud') loadGroups();
      refreshShares();
    }, 15000);
    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
    };
  }, []);

  // Requests location permission and starts the background task if it isn't
  // already running. Called as a side effect of the action that actually
  // needs sharing on (making a group visible, creating a share link) -
  // there's no separate master switch to flip first.
  async function ensureSharing(): Promise<boolean> {
    if (sharing) return true;

    const { status: fg } =
      await Location.requestForegroundPermissionsAsync();
    if (fg !== 'granted') {
      Alert.alert(
        'Permission needed',
        'Location permission is required to share your position.',
      );
      return false;
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
      return false;
    }

    await startSharing();
    setSharing(true);
    return true;
  }

  // Stops the background task once nothing needs it any more: no group
  // you're visible in, and no active share link.
  function maybeStopSharing(nextGroups: GroupWithMembers[], nextShares: Share[]) {
    const stillNeeded = nextGroups.some((g) => g.visible) || nextShares.length > 0;
    if (!stillNeeded && sharing) {
      stopSharing();
      setSharing(false);
    }
  }

  async function handleGuestToggle() {
    if (sharing) {
      await stopSharing();
      stopGuestSharing(config);
      setSharing(false);
      return;
    }
    await ensureSharing();
  }

  async function handleCopyGroupInvite(group: GroupWithMembers) {
    await Clipboard.setStringAsync(group.inviteUrl);
    setCopiedGroupId(group.id);
    setTimeout(() => setCopiedGroupId(null), 2000);
  }

  async function handleToggleGroupVisibility(group: GroupWithMembers) {
    const next = !group.visible;
    if (next) {
      const ok = await ensureSharing();
      if (!ok) return;
    }
    const ok = await setGroupVisibility(config, group.id, next);
    if (!ok) return;
    const updated = groups.map((g) => (g.id === group.id ? { ...g, visible: next } : g));
    setGroups(updated);
    if (!next) maybeStopSharing(updated, shares);
    const members = await fetchMembers(config, group.id);
    setGroups((prev) => prev.map((g) => (g.id === group.id ? { ...g, members } : g)));
  }

  async function handleCreateGroup() {
    const name = newGroupName.trim();
    if (!name) return;
    setCreatingGroup(true);
    const group = await createGroup(config, name);
    setCreatingGroup(false);
    if (!group) return;
    setGroups((prev) => [...prev, { ...group, members: [] }]);
    setNewGroupName('');
  }

  async function handleJoinGroup() {
    const parsed = parseInviteUrl(joinInviteUrl.trim());
    if (!parsed) {
      Alert.alert('Invalid link', 'Paste the full invite link you received.');
      return;
    }
    setJoiningGroup(true);
    const ok = await joinGroup(config, parsed.token);
    setJoiningGroup(false);
    if (!ok) {
      Alert.alert("Couldn't join", 'Check the link and try again.');
      return;
    }
    setJoinInviteUrl('');
    loadGroups();
  }

  function displayName(group: { id: number; name: string }): string {
    return nicknames[group.id] ?? group.name;
  }

  async function handleSetNickname(groupId: number, nickname: string) {
    const next = await setNickname(groupId, nickname);
    setNicknames(next);
  }

  async function handleRemoveMember(group: GroupWithMembers, userId: string) {
    const ok = await removeGroupMember(config, group.id, userId);
    if (!ok) return;
    setGroups((prev) =>
      prev.map((g) =>
        g.id === group.id
          ? { ...g, members: g.members.filter((m) => m.userId !== userId) }
          : g,
      ),
    );
  }

  async function handleDeleteGroup(group: GroupWithMembers) {
    Alert.alert(
      'Delete group',
      `Delete "${group.name}"? Everyone in it will lose access, including you.`,
      [
        {
          text: 'Delete',
          style: 'destructive',
          onPress: async () => {
            const ok = await deleteGroup(config, group.id);
            if (!ok) return;
            const updated = groups.filter((g) => g.id !== group.id);
            setGroups(updated);
            setOpenGroupId(null);
            maybeStopSharing(updated, shares);
          },
        },
        { text: 'Cancel', style: 'cancel' },
      ],
    );
  }

  async function handleCreateShare() {
    const ok = await ensureSharing();
    if (!ok) return;
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
    if (ok) {
      const updated = shares.filter((s) => s.id !== share.id);
      setShares(updated);
      maybeStopSharing(groups, updated);
    }
  }

  const openGroup = groups.find((g) => g.id === openGroupId) ?? null;

  return (
    <SafeAreaView style={styles.root}>
      {/* Header */}
      <View style={styles.header}>
        <Text style={styles.headerTitle}>LocShare</Text>
        <TouchableOpacity
          style={styles.settingsBtn}
          onPress={() => {
            Alert.alert(
              'Edit settings',
              'Sharing will stop while you edit your connection settings.',
              [
                {
                  text: 'Continue',
                  onPress: async () => {
                    await stopSharing();
                    onReconfigure();
                  },
                },
                { text: 'Cancel', style: 'cancel' },
              ],
            );
          }}
        >
          <Text style={styles.settingsIcon}>⚙️</Text>
        </TouchableOpacity>
      </View>

      <ScrollView contentContainerStyle={styles.scroll}>
        {openGroup ? (
          <GroupDetail
            group={openGroup}
            nickname={nicknames[openGroup.id] ?? ''}
            copied={copiedGroupId === openGroup.id}
            onCopyInvite={() => handleCopyGroupInvite(openGroup)}
            onBack={() => setOpenGroupId(null)}
            onRemoveMember={(userId) => handleRemoveMember(openGroup, userId)}
            onDeleteGroup={() => handleDeleteGroup(openGroup)}
            onSetNickname={(value) => handleSetNickname(openGroup.id, value)}
          />
        ) : (
          <>
            {/* ── Sharing status (read-only; derived from groups + share links) ── */}
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
                    {config.mode === 'guest'
                      ? sharing
                        ? 'Updating every ~5 s in background'
                        : 'Tap to start background sharing'
                      : sharing
                      ? 'Visible via an active group or share link'
                      : 'Turn on a group or create a share link to start'}
                  </Text>
                </View>
                {config.mode === 'guest' &&
                  (statusChecked ? (
                    <Switch
                      value={sharing}
                      onValueChange={handleGuestToggle}
                      trackColor={{ true: PRIMARY, false: '#d1d5db' }}
                      thumbColor="#fff"
                    />
                  ) : (
                    <ActivityIndicator color={PRIMARY} />
                  ))}
              </View>
            </View>

            {/* ── Share links (authenticated only) ── */}
            {config.mode === 'nextcloud' && (
              <View style={styles.card}>
                <Text style={styles.sectionLabel}>Share my location</Text>
                <Text style={styles.sectionNote}>
                  A temporary link anyone can open to watch, no account needed.
                </Text>

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

            {/* ── Groups overview (authenticated only) ── */}
            {config.mode === 'nextcloud' && (
              <View style={styles.card}>
                <Text style={styles.sectionLabel}>Groups</Text>
                <Text style={styles.sectionNote}>
                  Everyone in a group can see each other's location.
                </Text>

                {groups.length === 0 && !groupsFailed && (
                  <Text style={styles.emptyNote}>Loading…</Text>
                )}

                {groups.length === 0 && groupsFailed && (
                  <View style={styles.retryBlock}>
                    <Text style={styles.retryNote}>Couldn't reach the server.</Text>
                    <TouchableOpacity style={styles.retryBtn} onPress={loadGroups}>
                      <Text style={styles.retryBtnText}>Retry</Text>
                    </TouchableOpacity>
                  </View>
                )}

                {groups.map((group, i) => (
                  <View
                    key={group.id}
                    style={[styles.groupOverviewRow, i > 0 && styles.groupBlockBorder]}
                  >
                    <TouchableOpacity
                      style={styles.groupNameTouchable}
                      onPress={() => setOpenGroupId(group.id)}
                    >
                      <Text style={styles.groupName} numberOfLines={1}>
                        {displayName(group)}
                      </Text>
                      <Text style={styles.chevron}>›</Text>
                    </TouchableOpacity>
                    <View style={styles.groupVisibleRow}>
                      <Text style={styles.groupVisibleLabel}>Visible here</Text>
                      <Switch
                        value={group.visible}
                        onValueChange={() => handleToggleGroupVisibility(group)}
                        trackColor={{ true: PRIMARY, false: '#d1d5db' }}
                        thumbColor="#fff"
                      />
                    </View>
                  </View>
                ))}

                <View style={styles.newGroupRow}>
                  <TextInput
                    style={styles.newGroupInput}
                    value={joinInviteUrl}
                    onChangeText={setJoinInviteUrl}
                    placeholder="Paste invite link to join"
                    placeholderTextColor="#aaa"
                    autoCapitalize="none"
                    autoCorrect={false}
                  />
                  <TouchableOpacity
                    style={[
                      styles.newGroupBtn,
                      (!joinInviteUrl.trim() || joiningGroup) && styles.createBtnDisabled,
                    ]}
                    onPress={handleJoinGroup}
                    disabled={!joinInviteUrl.trim() || joiningGroup}
                  >
                    {joiningGroup ? (
                      <ActivityIndicator color="#fff" size="small" />
                    ) : (
                      <Text style={styles.newGroupBtnText}>Join</Text>
                    )}
                  </TouchableOpacity>
                </View>

                <View style={styles.newGroupRow}>
                  <TextInput
                    style={styles.newGroupInput}
                    value={newGroupName}
                    onChangeText={setNewGroupName}
                    placeholder="New group name"
                    placeholderTextColor="#aaa"
                  />
                  <TouchableOpacity
                    style={[
                      styles.newGroupBtn,
                      (!newGroupName.trim() || creatingGroup) && styles.createBtnDisabled,
                    ]}
                    onPress={handleCreateGroup}
                    disabled={!newGroupName.trim() || creatingGroup}
                  >
                    {creatingGroup ? (
                      <ActivityIndicator color="#fff" size="small" />
                    ) : (
                      <Text style={styles.newGroupBtnText}>+ Create</Text>
                    )}
                  </TouchableOpacity>
                </View>
              </View>
            )}
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

// ── GroupDetail ──────────────────────────────────────────────────────────────

function GroupDetail({
  group,
  nickname,
  copied,
  onCopyInvite,
  onBack,
  onRemoveMember,
  onDeleteGroup,
  onSetNickname,
}: {
  group: GroupWithMembers;
  nickname: string;
  copied: boolean;
  onCopyInvite: () => void;
  onBack: () => void;
  onRemoveMember: (userId: string) => void;
  onDeleteGroup: () => void;
  onSetNickname: (value: string) => void;
}) {
  const [nicknameDraft, setNicknameDraft] = useState(nickname);

  useEffect(() => {
    setNicknameDraft(nickname);
  }, [group.id, nickname]);

  return (
    <>
      <TouchableOpacity style={styles.backRow} onPress={onBack}>
        <Text style={styles.backText}>‹ Groups</Text>
      </TouchableOpacity>

      <View style={styles.card}>
        <Text style={styles.sectionLabel}>{nickname || group.name}</Text>
        <Text style={styles.sectionNote}>
          {group.visible
            ? "You're visible to this group."
            : "You're hidden from this group."}
        </Text>

        <TextInput
          style={styles.nicknameInput}
          value={nicknameDraft}
          onChangeText={setNicknameDraft}
          onEndEditing={() => onSetNickname(nicknameDraft)}
          placeholder={group.name}
          placeholderTextColor="#aaa"
        />
        <Text style={[styles.sectionNote, { marginTop: 4 }]}>
          Your name for this group. Only visible to you.
        </Text>

        {group.members.length === 0 ? (
          <Text style={styles.emptyNote}>No positions yet</Text>
        ) : (
          group.members.map((m) => (
            <MemberRow
              key={m.userId}
              member={m}
              onRemove={
                group.isOwner && !m.isMe ? () => onRemoveMember(m.userId) : undefined
              }
            />
          ))
        )}

        <View style={[styles.shareRow, { marginTop: 12 }]}>
          <View style={styles.shareInfo}>
            <Text style={styles.shareUrl} numberOfLines={1}>
              {group.inviteUrl}
            </Text>
          </View>
          <TouchableOpacity style={styles.iconBtn} onPress={onCopyInvite}>
            <Text style={styles.iconText}>{copied ? '✓' : '📋'}</Text>
          </TouchableOpacity>
        </View>

        {group.isOwner && (
          <TouchableOpacity style={styles.deleteGroupBtn} onPress={onDeleteGroup}>
            <Text style={styles.deleteGroupBtnText}>Delete group</Text>
          </TouchableOpacity>
        )}
      </View>
    </>
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

function MemberRow({
  member,
  onRemove,
}: {
  member: Member;
  onRemove?: () => void;
}) {
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
      {onRemove && (
        <TouchableOpacity
          style={styles.memberRemoveBtn}
          onPress={() =>
            Alert.alert(
              'Remove member',
              `Remove ${member.displayName} from this group?`,
              [
                { text: 'Remove', style: 'destructive', onPress: onRemove },
                { text: 'Cancel', style: 'cancel' },
              ],
            )
          }
        >
          <Text style={styles.memberRemoveText}>✕</Text>
        </TouchableOpacity>
      )}
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
  sectionNote: {
    fontSize: 12,
    color: '#6b7280',
    marginTop: -8,
    marginBottom: 14,
  },

  groupOverviewRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
    paddingVertical: 10,
  },
  groupBlockBorder: { borderTopWidth: 1, borderTopColor: '#f0f0f0' },
  groupNameTouchable: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    minWidth: 0,
  },
  groupName: { fontSize: 14, fontWeight: '600', color: '#111', flexShrink: 1 },
  chevron: { fontSize: 16, color: '#c1c7cf' },
  groupVisibleRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  groupVisibleLabel: { fontSize: 11, color: '#9ca3af' },

  backRow: { paddingVertical: 4, paddingHorizontal: 2 },
  backText: { fontSize: 15, fontWeight: '600', color: PRIMARY },

  nicknameInput: {
    backgroundColor: '#fafafa',
    borderWidth: 1,
    borderColor: '#d1d5db',
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 8,
    fontSize: 14,
    color: '#111',
    marginTop: 4,
  },

  newGroupRow: { flexDirection: 'row', gap: 8, marginTop: 12 },
  newGroupInput: {
    flex: 1,
    backgroundColor: '#fafafa',
    borderWidth: 1,
    borderColor: '#d1d5db',
    borderRadius: 8,
    paddingHorizontal: 12,
    fontSize: 14,
    color: '#111',
  },
  newGroupBtn: {
    backgroundColor: PRIMARY,
    borderRadius: 8,
    paddingHorizontal: 16,
    alignItems: 'center',
    justifyContent: 'center',
  },
  newGroupBtnText: { color: '#fff', fontWeight: '600', fontSize: 13 },

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
  memberRemoveBtn: { paddingLeft: 10, paddingVertical: 4 },
  memberRemoveText: { fontSize: 15, color: '#c1c7cf', fontWeight: '700' },

  deleteGroupBtn: { alignItems: 'center', marginTop: 16, paddingVertical: 6 },
  deleteGroupBtnText: { fontSize: 13, fontWeight: '600', color: '#dc2626' },

  emptyNote: { fontSize: 14, color: '#9ca3af', textAlign: 'center', paddingVertical: 8 },

  retryBlock: { alignItems: 'center', paddingVertical: 8, gap: 10 },
  retryNote: { fontSize: 14, color: '#dc2626' },
  retryBtn: {
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: PRIMARY,
  },
  retryBtnText: { fontSize: 13, fontWeight: '600', color: PRIMARY },
});
