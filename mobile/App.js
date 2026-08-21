import React, { useState, useEffect, useRef, useCallback } from 'react';
import {
  StyleSheet,
  Text,
  View,
  Image,
  TextInput,
  TouchableOpacity,
  SafeAreaView,
  ScrollView,
  Alert,
  ActivityIndicator,
  RefreshControl,
  Modal,
  StatusBar as RNStatusBar,
  PanResponder,
  Animated,
  Dimensions,
  Platform,
} from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { registerRootComponent } from 'expo';
import * as Updates from 'expo-updates';
import apiClient, { setAuthToken, setBaseUrl } from './src/api/client';

const SCREEN_WIDTH = Dimensions.get('window').width;
const SWIPE_THRESHOLD = SCREEN_WIDTH * 0.3; // 30% of screen width triggers action

/**
 * SwipeableQcItem: A card that can be swiped right (accept) or left (reject/rework).
 * - Arrival tab: swipe right = accept arrival, swipe left = reject & return
 * - Inspection tab: swipe right = approve, swipe left = reject
 */
function SwipeableQcItem({ item, qcSubTab, onAccept, onReject, onRework }) {
  const translateX = useRef(new Animated.Value(0)).current;
  const opacity = useRef(new Animated.Value(1)).current;

  const panResponder = useRef(
    PanResponder.create({
      onMoveShouldSetPanResponder: (_, { dx, dy }) => Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10,
      onPanResponderMove: (_, { dx }) => {
        translateX.setValue(dx);
      },
      onPanResponderRelease: (_, { dx }) => {
        if (dx > SWIPE_THRESHOLD) {
          // Swipe RIGHT → Accept / Approve
          Animated.parallel([
            Animated.timing(translateX, { toValue: SCREEN_WIDTH, duration: 250, useNativeDriver: true }),
            Animated.timing(opacity, { toValue: 0, duration: 250, useNativeDriver: true }),
          ]).start(() => {
            translateX.setValue(0);
            opacity.setValue(1);
            onAccept(item);
          });
        } else if (dx < -SWIPE_THRESHOLD) {
          // Swipe LEFT → Reject / Return
          Animated.parallel([
            Animated.timing(translateX, { toValue: -SCREEN_WIDTH, duration: 250, useNativeDriver: true }),
            Animated.timing(opacity, { toValue: 0, duration: 250, useNativeDriver: true }),
          ]).start(() => {
            translateX.setValue(0);
            opacity.setValue(1);
            onReject(item);
          });
        } else {
          // Snap back
          Animated.spring(translateX, { toValue: 0, useNativeDriver: true }).start();
        }
      },
    })
  ).current;

  const bgColor = translateX.interpolate({
    inputRange: [-SCREEN_WIDTH / 2, 0, SCREEN_WIDTH / 2],
    outputRange: ['#ef4444', '#ffffff', '#10b981'],
    extrapolate: 'clamp',
  });

  return (
    <View style={{ position: 'relative', overflow: 'hidden', borderRadius: 12, marginBottom: 12 }}>
      {/* Background hint layer */}
      <Animated.View style={{
        position: 'absolute', inset: 0,
        backgroundColor: bgColor,
        borderRadius: 12,
        justifyContent: 'center',
        paddingHorizontal: 20,
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
      }}>
        <Text style={{ color: '#fff', fontWeight: '800', fontSize: 15, letterSpacing: 1 }}>ACCEPT</Text>
        <Text style={{ color: '#fff', fontWeight: '800', fontSize: 15, letterSpacing: 1 }}>REJECT</Text>
      </Animated.View>

      {/* Swipeable card */}
      <Animated.View
        style={{ transform: [{ translateX }], opacity }}
        {...panResponder.panHandlers}
      >
        <View style={[swipeCardStyles.card]}>
          {/* Swipe hint */}
          <View style={swipeCardStyles.swipeHintRow}>
            <Text style={swipeCardStyles.swipeHintLeft}>‹ REJECT</Text>
            <Text style={swipeCardStyles.swipeHintTitle}>
              {qcSubTab === 'arrival' ? 'PHYSICAL ARRIVAL CHECK' : 'QUALITY INSPECTION'}
            </Text>
            <Text style={swipeCardStyles.swipeHintRight}>ACCEPT ›</Text>
          </View>

          <View style={swipeCardStyles.partRow}>
            <Text style={swipeCardStyles.partNo}>{item.standard_part_no || item.bom_item?.standard_part_no || `Item #${item.id}`}</Text>
            <Text style={swipeCardStyles.status}>{(item.status || '').toUpperCase()}</Text>
          </View>

          {item.bom_item?.project && (
            <Text style={swipeCardStyles.meta}>Project: {item.bom_item.project.name}</Text>
          )}
          <Text style={swipeCardStyles.meta}>
            Side: <Text style={{ fontWeight: '700' }}>{item.side || 'COMMON'}</Text>  |  Qty: <Text style={{ fontWeight: '700' }}>{item.received_quantity || item.quantity || 1}</Text>
          </Text>

          {/* For inspection tab, show extra Rework button */}
          {qcSubTab === 'inspection' && onRework && (
            <TouchableOpacity
              style={swipeCardStyles.reworkBtn}
              onPress={() => onRework(item)}
            >
              <Text style={swipeCardStyles.reworkBtnText}>TAP TO OPEN INSPECT FORM</Text>
            </TouchableOpacity>
          )}
        </View>
      </Animated.View>
    </View>
  );
}

const swipeCardStyles = StyleSheet.create({
  card: {
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 14,
    shadowColor: '#000',
    shadowOpacity: 0.1,
    shadowRadius: 8,
    elevation: 4,
    borderWidth: 1,
    borderColor: '#e5e7eb',
  },
  swipeHintRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 8,
    paddingBottom: 8,
    borderBottomWidth: 1,
    borderBottomColor: '#f3f4f6',
  },
  swipeHintLeft: { color: '#ef4444', fontSize: 11, fontWeight: '700' },
  swipeHintRight: { color: '#10b981', fontSize: 11, fontWeight: '700' },
  swipeHintTitle: { color: '#6b7280', fontSize: 11, flex: 1, textAlign: 'center' },
  partRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 },
  partNo: { fontSize: 16, fontWeight: '800', color: '#1e40af' },
  status: { fontSize: 10, fontWeight: '700', color: '#6b7280', backgroundColor: '#f3f4f6', paddingHorizontal: 6, paddingVertical: 2, borderRadius: 4 },
  meta: { fontSize: 12, color: '#6b7280', marginTop: 3 },
  reworkBtn: {
    marginTop: 10,
    backgroundColor: '#fef3c7',
    borderWidth: 1,
    borderColor: '#f59e0b',
    borderRadius: 8,
    paddingVertical: 8,
    alignItems: 'center',
  },
  reworkBtnText: { color: '#92400e', fontWeight: '700', fontSize: 13 },
});

function App() {
  const [token, setToken] = useState(null);
  const [user, setUser] = useState(null);
  const [userRole, setUserRole] = useState('');
  const [serverHost, setServerHost] = useState('192.168.100.8:8000');
  const [email, setEmail] = useState('admin@sparetrack.internal');
  const [password, setPassword] = useState('password123');

  const [activeTab, setActiveTab] = useState('dashboard');
  const [storeSubTab, setStoreSubTab] = useState('pending'); // 'pending' | 'returned' | 'history'
  const [qcSubTab, setQcSubTab] = useState('arrival'); // 'arrival' | 'inspection'
  const [summary, setSummary] = useState(null);
  const [items, setItems] = useState([]);
  const [historyItems, setHistoryItems] = useState([]);
  const [returnedItems, setReturnedItems] = useState([]);
  const [projects, setProjects] = useState([]);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');

  // Search & Filter state - Per-Tab Isolated Search State (Part 13)
  const [tabSearches, setTabSearches] = useState({
    store_pending: '',
    store_returned: '',
    store_history: '',
    qc_arrival: '',
    qc_inspection: '',
    rework: '',
    paint: '',
    assembly: '',
    purchase: '',
  });

  const [paintStatusFilter, setPaintStatusFilter] = useState('all'); // 'all' | 'active' | 'completed'
  const [selectedSide, setSelectedSide] = useState('');
  const [selectedProject, setSelectedProject] = useState('');
  const [showFilterModal, setShowFilterModal] = useState(false);
  const searchTimer = useRef(null);
  const mainScrollRef = useRef(null);

  const scrollToTop = useCallback((animated = false) => {
    if (mainScrollRef.current) {
      mainScrollRef.current.scrollTo({ y: 0, animated });
    }
  }, []);

  const getCurrentSearchKey = (tab = activeTab, storeSub = storeSubTab, qcSub = qcSubTab) => {
    if (tab === 'store') {
      if (storeSub === 'history') return 'store_history';
      if (storeSub === 'returned') return 'store_returned';
      return 'store_pending';
    }
    if (tab === 'qc') return qcSub === 'arrival' ? 'qc_arrival' : 'qc_inspection';
    return tab;
  };

  const currentSearchQuery = tabSearches[getCurrentSearchKey()] || '';

  // Store Receive Modal state
  const [showReceiveModal, setShowReceiveModal] = useState(false);
  const [selectedItemForReceive, setSelectedItemForReceive] = useState(null);
  const [receiveSide, setReceiveSide] = useState('RH');
  const [receiveQty, setReceiveQty] = useState('1');
  const [deliveryNote, setDeliveryNote] = useState('');
  const [isSubmittingReceive, setIsSubmittingReceive] = useState(false); // Idempotency: prevent duplicate receipt submissions

  // Rework Completion Modal state
  const [showReworkModal, setShowReworkModal] = useState(false);
  const [selectedReworkItem, setSelectedReworkItem] = useState(null);
  const [reworkNotes, setReworkNotes] = useState('');

  // QC Inspection Modal state
  const [showQcModal, setShowQcModal] = useState(false);
  const [selectedQcItem, setSelectedQcItem] = useState(null);
  const [qcResult, setQcResult] = useState('approved'); // 'approved' | 'rejected' | 'rework' | 'partial'
  const [qcDestination, setQcDestination] = useState(''); // 'PAINT' | 'ASSEMBLY'
  const [qcApprovedQty, setQcApprovedQty] = useState('1');
  const [qcRejectedQty, setQcRejectedQty] = useState('0');
  const [qcReworkQty, setQcReworkQty] = useState('0');
  const [qcReason, setQcReason] = useState('');
  const [qcRemarks, setQcRemarks] = useState('');

  // Paint Modal state
  const [showPaintModal, setShowPaintModal] = useState(false);
  const [selectedPaintItem, setSelectedPaintItem] = useState(null);
  const [paintType, setPaintType] = useState('RAL 7035 Powder Coat');
  const [paintRemarks, setPaintRemarks] = useState('');

  // Multi-Selection & Bulk Operations State (Issue 5)
  const [isSelectionMode, setIsSelectionMode] = useState(false);
  const [selectedItemIds, setSelectedItemIds] = useState(new Set());
  const [showBulkStoreReceiveModal, setShowBulkStoreReceiveModal] = useState(false);
  const [bulkDeliveryNote, setBulkDeliveryNote] = useState('');
  const [showBulkQcDestinationModal, setShowBulkQcDestinationModal] = useState(false);
  const [showBulkPaintModal, setShowBulkPaintModal] = useState(false);
  const [showBulkReworkModal, setShowBulkReworkModal] = useState(false);
  const [bulkPaintType, setBulkPaintType] = useState('RAL 7035 Powder Coat');
  const [bulkReworkNotes, setBulkReworkNotes] = useState('');

  // Non-blocking Toast notification state (Issue 4)
  const [toast, setToast] = useState({ visible: false, message: '', type: 'success' });
  const showToast = (message, type = 'success') => {
    setToast({ visible: true, message, type });
    setTimeout(() => {
      setToast(prev => ({ ...prev, visible: false }));
    }, 2800);
  };

  // Mobile Store Hierarchy State
  const [hierarchyJigs, setHierarchyJigs] = useState([]);
  const [hierarchyProject, setHierarchyProject] = useState(null);
  const [selectedJig, setSelectedJig] = useState(null);
  const [selectedUnit, setSelectedUnit] = useState(null);
  const [unitSideTab, setUnitSideTab] = useState('LH'); // 'LH' | 'RH'

  const getItemSelectionKey = (item, side = unitSideTab) => `${item.id}_${side}`;

  const toggleSelection = (item, side = unitSideTab) => {
    const key = getItemSelectionKey(item, side);
    setSelectedItemIds(prev => {
      const next = new Set(prev);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      if (next.size === 0) setIsSelectionMode(false);
      else setIsSelectionMode(true);
      return next;
    });
  };

  const selectAllVisible = (visibleItems, side = unitSideTab) => {
    const allKeys = visibleItems.map(i => getItemSelectionKey(i, side));
    setSelectedItemIds(new Set(allKeys));
    setIsSelectionMode(allKeys.length > 0);
  };

  const clearSelection = () => {
    setSelectedItemIds(new Set());
    setIsSelectionMode(false);
  };

  const getSearchPlaceholder = () => {
    if (activeTab === 'store') {
      if (storeSubTab === 'history') return '🔍 Search receipts history...';
      if (storeSubTab === 'returned') return '🔍 Search QC-returned parts...';
      if (!selectedProject) return '🔍 Search projects by name / code...';
      if (!selectedJig) return '🔍 Search JIGs (e.g. ST7)...';
      if (!selectedUnit) return '🔍 Search units (e.g. 07, Unit 07)...';
      return '🔍 Search pending parts in this unit...';
    }
    if (activeTab === 'qc') return qcSubTab === 'arrival' ? '🔍 Search arrival queue...' : '🔍 Search inspection queue...';
    if (activeTab === 'paint') return '🔍 Search Paint queue...';
    if (activeTab === 'assembly') return '🔍 Search Assembly queue...';
    if (activeTab === 'rework') return '🔍 Search Rework items...';
    if (activeTab === 'purchase') return '🔍 Search Purchase queue...';
    return '🔍 Search items...';
  };

  // Sync baseUrl with serverHost state
  useEffect(() => {
    if (serverHost) {
      setBaseUrl(serverHost);
    }
  }, [serverHost]);

  // Reactive Context Transition Watcher: Reset scroll to top and clear selection when navigating screens
  const screenContextKey = `${activeTab}_${storeSubTab}_${qcSubTab}_${paintStatusFilter}_${selectedProject || ''}_${selectedJig?.id || selectedJig?.jig_name || ''}_${selectedUnit?.unit_no || ''}_${unitSideTab}`;

  useEffect(() => {
    scrollToTop(false);
    clearSelection();
  }, [screenContextKey, scrollToTop]);

  // 30s Polling Loop for live real-time updates
  useEffect(() => {
    if (!token) return;
    const interval = setInterval(() => {
      loadData(activeTab, false);
    }, 30000);
    return () => clearInterval(interval);
  }, [token, activeTab, storeSubTab, tabSearches, selectedSide, selectedProject]);

  const [otaChecking, setOtaChecking] = useState(false);

  const handleCheckOtaUpdate = async () => {
    setOtaChecking(true);
    try {
      if (__DEV__) {
        Alert.alert('Development Build', 'Running in local Expo development mode. OTA updates apply to preview/production builds.');
        return;
      }
      const update = await Updates.checkForUpdateAsync();
      if (update.isAvailable) {
        showToast('Downloading Phase 4 update...');
        await Updates.fetchUpdateAsync();
        Alert.alert(
          'Update Downloaded',
          'A new update has been downloaded. Would you like to restart the app to apply it now?',
          [
            { text: 'Later', style: 'cancel' },
            { text: 'Restart Now', onPress: () => Updates.reloadAsync() }
          ]
        );
      } else {
        Alert.alert('Up to Date', 'Your app is running the latest Phase 4 update (v2.4.0).');
      }
    } catch (e) {
      Alert.alert('App Version Info', `Running Build v2.4.0 (Phase 4).\nChannel: preview\nStatus: Latest bundle active`);
    } finally {
      setOtaChecking(false);
    }
  };

  const handleLogin = async () => {
    if (!email || !password) {
      Alert.alert('Missing Fields', 'Please enter email and password.');
      return;
    }

    setErrorMsg('');
    setLoading(true);

    try {
      if (serverHost) {
        setBaseUrl(serverHost);
      }

      const res = await apiClient.post('/auth/login', { email, password });
      const userToken = res.data.token;
      const loggedUser = res.data.user;
      const role = loggedUser?.roles?.[0]?.name || res.data.role || 'USER';

      setToken(userToken);
      setUser(loggedUser);
      setUserRole(role);
      setAuthToken(userToken);

      // Auto-set tab based on role
      if (role === 'STORE') setActiveTab('store');
      else if (role === 'QC') setActiveTab('qc');
      else if (role === 'REWORK') setActiveTab('rework');
      else if (role === 'PAINT') setActiveTab('paint');
      else if (role === 'ASSEMBLY') setActiveTab('assembly');
      else if (role === 'PURCHASE') setActiveTab('purchase');
      else setActiveTab('dashboard');

      await loadData(role === 'STORE' ? 'store' : role === 'QC' ? 'qc' : 'dashboard');
    } catch (err) {
      const msg = err.response?.data?.message || err.message || 'Could not connect to server.';
      const targetUrl = `${apiClient.defaults.baseURL || getBaseUrl()}/auth/login`;
      setErrorMsg(`Connection Error: ${msg}\n\nTarget Endpoint: ${targetUrl}\n\nPlease ensure phone is on the same Wi-Fi and Mobile Data is turned OFF.`);
      Alert.alert('Login Failed', `${msg}\n\nTarget: ${targetUrl}`);
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = () => {
    Alert.alert('Logout', 'Are you sure you want to log out?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Logout',
        style: 'destructive',
        onPress: () => {
          setToken(null);
          setUser(null);
          setUserRole('');
          setAuthToken(null);
          setSummary(null);
          setItems([]);
          setHistoryItems([]);
          setSelectedJig(null);
          setSelectedUnit(null);
          setSelectedProject('');
        }
      }
    ]);
  };

  const extractArray = (resData) => {
    if (!resData) return [];
    if (Array.isArray(resData)) return resData;
    if (Array.isArray(resData.data)) return resData.data;
    if (Array.isArray(resData.data?.data)) return resData.data.data;
    if (Array.isArray(resData.items)) return resData.items;
    if (Array.isArray(resData.items?.data)) return resData.items.data;
    if (Array.isArray(resData.queue)) return resData.queue;
    if (Array.isArray(resData.queue?.data)) return resData.queue.data;
    return [];
  };

  const loadData = async (tab = activeTab, showSpinner = true, customSearch = null) => {
    if (showSpinner) setLoading(true);
    try {
      const activeSearch = customSearch !== null ? customSearch : (tabSearches[getCurrentSearchKey(tab, storeSubTab, qcSubTab)] || '');
      const params = { per_page: 100 };
      if (activeSearch) params.search = activeSearch;
      if (selectedSide) params.side = selectedSide;
      if (selectedProject) params.project_id = selectedProject;

      if (tab === 'dashboard') {
        const res = await apiClient.get('/dashboard/summary', { params });
        setSummary(res.data.summary || res.data);
      } else if (tab === 'store' && storeSubTab === 'history') {
        const res = await apiClient.get('/store/history', { params });
        setHistoryItems(extractArray(res.data));
      } else if (tab === 'store' && storeSubTab === 'returned') {
        const res = await apiClient.get('/store/returned', { params });
        setReturnedItems(extractArray(res.data));
      } else if (tab === 'purchase') {
        const res = await apiClient.get('/purchase/items', { params });
        setItems(extractArray(res.data));
      } else {
        // Operational department hierarchy: store, qc, rework, paint, assembly
        const hierarchyEndpoint = `/${tab}/hierarchy`;
        const res = await apiClient.get(hierarchyEndpoint, { params: { project_id: selectedProject, side: selectedSide, search: activeSearch } });
        if (res.data.projects) setProjects(res.data.projects);
        if (res.data.is_hierarchical) {
          const updatedJigs = res.data.jigs || [];
          setHierarchyJigs(updatedJigs);
          setHierarchyProject(res.data.project || null);

          // Sync selectedJig and selectedUnit references with newly fetched data
          if (selectedJig) {
            const newJig = updatedJigs.find(j => j.jig_name === selectedJig.jig_name);
            if (newJig) {
              setSelectedJig(newJig);
              if (selectedUnit) {
                const newUnit = newJig.units?.find(u => u.unit_no === selectedUnit.unit_no);
                if (newUnit) {
                  setSelectedUnit(newUnit);
                } else {
                  setSelectedUnit(null);
                }
              }
            } else {
              setSelectedJig(null);
              setSelectedUnit(null);
            }
          }
        } else {
          setHierarchyJigs([]);
          setHierarchyProject(null);
        }
      }
    } catch (err) {
      console.log(`Error loading ${tab} data:`, err);
    } finally {
      if (showSpinner) setLoading(false);
      setRefreshing(false);
    }
  };

  const handleSearchChange = (text) => {
    const key = getCurrentSearchKey();
    setTabSearches(prev => ({ ...prev, [key]: text }));
    clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => {
      loadData(activeTab, true, text);
    }, 300);
  };

  const handleClearSearch = () => {
    const key = getCurrentSearchKey();
    setTabSearches(prev => ({ ...prev, [key]: '' }));
    loadData(activeTab, false, '');
  };

  const onRefresh = () => {
    setRefreshing(true);
    loadData(activeTab);
  };

  const handleTabChange = (tab) => {
    setActiveTab(tab);
    loadData(tab);
  };

  const handleSelectProject = async (projId) => {
    setSelectedProject(projId);
    setLoading(true);
    try {
      const hierarchyEndpoint = `/${activeTab === 'dashboard' ? 'store' : activeTab}/hierarchy`;
      const res = await apiClient.get(hierarchyEndpoint, { params: { project_id: projId, side: selectedSide } });
      if (res.data.is_hierarchical) {
        setHierarchyJigs(res.data.jigs || []);
        setHierarchyProject(res.data.project || null);
      } else {
        setHierarchyJigs([]);
        setHierarchyProject(null);
      }
    } catch (err) {
      console.log("Error selecting project:", err);
    } finally {
      setLoading(false);
    }
  };

  const handleResetProject = () => {
    setSelectedProject('');
    setSelectedJig(null);
    setSelectedUnit(null);
    setHierarchyJigs([]);
    setHierarchyProject(null);
    loadData(activeTab);
  };

  // --- STORE ACTIONS ---
  const openReceiveModal = (item, defaultSide = 'RH') => {
    setSelectedItemForReceive(item);
    setReceiveSide(defaultSide);
    const pending = item.side_stats?.[defaultSide]?.pending ?? 1;
    setReceiveQty(String(pending > 0 ? pending : 1));
    setDeliveryNote(`DN-${Date.now().toString().slice(-4)}`);
    setShowReceiveModal(true);
  };

  const submitStoreReceive = async () => {
    if (!selectedItemForReceive) return;
    if (isSubmittingReceive) return;  // Idempotency guard: prevent double-tap
    const qty = parseInt(receiveQty, 10);
    if (isNaN(qty) || qty <= 0) {
      Alert.alert('Invalid Quantity', 'Please enter a valid quantity greater than 0.');
      return;
    }

    setIsSubmittingReceive(true);
    try {
      await apiClient.post('/store/receipts', {
        project_id: selectedItemForReceive.project_id,
        delivery_note_number: deliveryNote,
        items: [
          {
            bom_item_id: selectedItemForReceive.id,
            side: receiveSide,
            received_quantity: qty,
          }
        ]
      });

      // Optimistic update for partial receipts in current unit view
      if (selectedUnit && selectedUnit.parts) {
        setSelectedUnit(prevUnit => {
          if (!prevUnit) return prevUnit;
          const updatedParts = (prevUnit.parts || []).map(p => {
            if (p.id !== selectedItemForReceive.id) return p;
            const updatedSideStats = { ...(p.side_stats || {}) };
            if (updatedSideStats[receiveSide]) {
              const currentPending = updatedSideStats[receiveSide].pending ?? 0;
              const newPending = Math.max(0, currentPending - qty);
              const currentReceived = updatedSideStats[receiveSide].received ?? 0;
              updatedSideStats[receiveSide] = {
                ...updatedSideStats[receiveSide],
                received: currentReceived + qty,
                pending: newPending,
                status: newPending === 0 ? 'received' : 'partially_received',
              };
            }
            return {
              ...p,
              side_stats: updatedSideStats,
            };
          });
          return {
            ...prevUnit,
            parts: updatedParts,
          };
        });
      }

      setShowReceiveModal(false);
      showToast(`Received ${qty} pcs (${receiveSide}) for ${selectedItemForReceive.standard_part_no}`);
      loadData('store', false);
    } catch (err) {
      Alert.alert('Receive Failed', err.response?.data?.message || 'Could not record store receipt.');
    } finally {
      setIsSubmittingReceive(false);  // Always release the lock
    }
  };

  const handleSendToQc = async (itemId) => {
    try {
      await apiClient.post(`/store/items/${itemId}/send-to-qc`);
      showToast('Item dispatched to QC queue');
      loadData('store', false);
    } catch (err) {
      Alert.alert('Error', err.response?.data?.message || 'Failed to dispatch item to QC.');
    }
  };

  const handleRevertReceipt = (historyItem) => {
    Alert.alert(
      'Revert Stock Receipt',
      `Are you sure you want to revert receipt of ${historyItem.received_quantity} pcs (${historyItem.side}) for ${historyItem.bom_item?.standard_part_no || 'this part'}?\n\nThis will undo the receipt and restore pending arrival stock.`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Revert / Undo',
          style: 'destructive',
          onPress: async () => {
            try {
              const res = await apiClient.post(`/store/items/${historyItem.id}/revert`);
              showToast(res.data.message || 'Stock receipt successfully undone.');
              loadData('store', false);
            } catch (err) {
              Alert.alert('Revert Failed', err.response?.data?.message || 'Could not revert stock receipt.');
            }
          }
        }
      ]
    );
  };

  // --- QC ACTIONS ---
  const handleConfirmQcPhysicalArrival = async (receiptItemId, partNo = '') => {
    try {
      await apiClient.post('/qc/receive', { receipt_item_id: receiptItemId });
      showToast(`Physical Arrival Confirmed: ${partNo || 'Item'}`);
      loadData('qc', false);
    } catch (err) {
      Alert.alert('Action Failed', err.response?.data?.message || 'Could not confirm physical QC arrival.');
    }
  };

  const handleRejectQcPhysicalArrival = (receiptItemId, partNo = '') => {
    Alert.alert(
      'Reject Physical Arrival',
      `Reject physical arrival for ${partNo || 'this part'}?\n\nThis sends the part back to Store verification (stock not physically delivered).`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Reject & Return Store',
          style: 'destructive',
          onPress: async () => {
            try {
              const res = await apiClient.post('/qc/reject-arrival', { receipt_item_id: receiptItemId });
              showToast(res.data.message || 'Item returned to store verification.', 'error');
              loadData('qc', false);
            } catch (err) {
              Alert.alert('Action Failed', err.response?.data?.message || 'Could not reject physical QC arrival.');
            }
          }
        }
      ]
    );
  };

  const openQcModal = (item, resultType) => {
    setSelectedQcItem(item);
    setQcResult(resultType);
    setQcDestination(''); // No default destination
    const qty = item.received_quantity || 1;
    if (resultType === 'approved') {
      setQcApprovedQty(String(qty));
      setQcRejectedQty('0');
      setQcReworkQty('0');
    } else if (resultType === 'rejected') {
      setQcApprovedQty('0');
      setQcRejectedQty(String(qty));
      setQcReworkQty('0');
    } else if (resultType === 'rework') {
      setQcApprovedQty('0');
      setQcRejectedQty('0');
      setQcReworkQty(String(qty));
    } else {
      setQcApprovedQty('0');
      setQcRejectedQty('0');
      setQcReworkQty('0');
    }
    setQcReason('');
    setQcRemarks('');
    setShowQcModal(true);
  };

  const submitQcInspection = async () => {
    if (!selectedQcItem) return;
    const avail = selectedQcItem.received_quantity || 1;
    const app = parseInt(qcApprovedQty, 10) || 0;
    const rej = parseInt(qcRejectedQty, 10) || 0;
    const rew = parseInt(qcReworkQty, 10) || 0;

    if (qcResult === 'approved' && !qcDestination) {
      Alert.alert('Destination Required', 'Please select whether approved parts route to Paint Station or Direct Assembly.');
      return;
    }

    if (qcResult === 'partial') {
      if (app + rej + rew !== avail) {
        Alert.alert('Quantity Error', `Sum of Approved (${app}) + Rework (${rew}) + Rejected (${rej}) must equal Available (${avail}).`);
        return;
      }
    }

    const payloadSide = selectedQcItem.side || unitSideTab || 'COMMON';
    const payloadReceiptId = selectedQcItem.id || selectedQcItem.receipt_item_id;
    const payloadBomId = selectedQcItem.bom_item_id || selectedQcItem.bom_item?.id;

    try {
      await apiClient.post('/qc/inspect', {
        receipt_item_id: payloadReceiptId,
        bom_item_id: payloadBomId,
        side: payloadSide,
        inspected_quantity: avail,
        result: qcResult,
        destination: qcResult === 'approved' ? qcDestination : null,
        approved_quantity: app,
        rejected_quantity: rej,
        rework_quantity: rew,
        rejection_reason: qcReason,
        rework_reason: qcReason,
        remarks: qcRemarks,
      });

      // Immediate optimistic removal from active QC queue
      if (selectedUnit && selectedUnit.parts) {
        setSelectedUnit(prevUnit => {
          if (!prevUnit) return prevUnit;
          const updatedParts = (prevUnit.parts || []).map(p => {
            if (p.id !== payloadBomId) return p;
            const updatedSideStats = { ...(p.side_stats || {}) };
            if (updatedSideStats[payloadSide]) {
              const prevInsp = updatedSideStats[payloadSide].qc_pending_inspection || 0;
              updatedSideStats[payloadSide] = {
                ...updatedSideStats[payloadSide],
                qc_pending_inspection: Math.max(0, prevInsp - avail),
                qc_approved: (updatedSideStats[payloadSide].qc_approved || 0) + app,
                qc_rejected: (updatedSideStats[payloadSide].qc_rejected || 0) + rej,
                qc_rework: (updatedSideStats[payloadSide].qc_rework || 0) + rew,
                paint_ready: qcDestination === 'PAINT' ? ((updatedSideStats[payloadSide].paint_ready || 0) + app) : (updatedSideStats[payloadSide].paint_ready || 0),
                assembly_ready: qcDestination === 'ASSEMBLY' ? ((updatedSideStats[payloadSide].assembly_ready || 0) + app) : (updatedSideStats[payloadSide].assembly_ready || 0),
              };
            }
            return {
              ...p,
              side_stats: updatedSideStats,
            };
          });
          return {
            ...prevUnit,
            parts: updatedParts,
          };
        });
      }

      setShowQcModal(false);
      showToast(`QC ${qcResult.toUpperCase()}: ${selectedQcItem.bom_item?.standard_part_no || 'Item'} (${payloadSide})`);
      loadData('qc', false);
    } catch (err) {
      Alert.alert('Inspection Failed', err.response?.data?.message || 'Could not record QC inspection.');
    }
  };

  // --- REWORK ACTIONS ---
  const handleStartRework = async (itemId, partNo = '') => {
    try {
      await apiClient.post(`/rework/items/${itemId}/start`);
      showToast(`Rework Started: ${partNo || 'Item'}`);
      loadData('rework', false);
    } catch (err) {
      Alert.alert('Error', err.response?.data?.message || 'Could not start rework.');
    }
  };

  const openReworkModal = (reworkRecord, bomItem) => {
    setSelectedReworkItem({
      ...reworkRecord,
      bom_item: bomItem,
    });
    setReworkNotes('');
    setShowReworkModal(true);
  };

  const submitReworkCompletion = async () => {
    if (!selectedReworkItem) return;
    try {
      await apiClient.post(`/rework/items/${selectedReworkItem.id}/complete`, {
        completion_notes: reworkNotes || 'Rework completed.',
        remarks: reworkNotes || 'Rework completed.',
      });
      setShowReworkModal(false);
      showToast(`Rework Completed: ${selectedReworkItem.bom_item?.standard_part_no || 'Item'}`);
      loadData('rework', false);
    } catch (err) {
      Alert.alert('Error', err.response?.data?.message || 'Could not complete rework.');
    }
  };

  // --- STORE RETURNED ACTIONS ---
  const handleProcessReturnedItem = async (item, action) => {
    Alert.alert(
      'Process Returned Part',
      `Confirm marking this returned part as "${action}"?`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Confirm',
          onPress: async () => {
            try {
              await apiClient.post(`/store/items/${item.id}/process-returned`, {
                action,
                remarks: `Processed via Mobile Store App as ${action}`,
              });
              showToast(`Processed as ${action}: ${item.bom_item?.standard_part_no || 'Item'}`);
              loadData('store', false);
            } catch (err) {
              Alert.alert('Error', err.response?.data?.message || 'Failed to process returned item.');
            }
          },
        },
      ]
    );
  };

  // --- PAINT ACTIONS ---
  const openPaintModal = (item) => {
    setSelectedPaintItem(item);
    setPaintType('RAL 7035 Powder Coat');
    setPaintRemarks('');
    setShowPaintModal(true);
  };

  const submitPaintCompletion = async () => {
    if (!selectedPaintItem) return;
    try {
      const payload = {
        bom_item_id: selectedPaintItem.bom_item_id || selectedPaintItem.bom_item?.id,
        qc_inspection_id: selectedPaintItem.id,
        side: selectedPaintItem.side || unitSideTab || 'COMMON',
        quantity: selectedPaintItem.approved_quantity || selectedPaintItem.quantity || 1,
        paint_type: paintType,
        remarks: paintRemarks,
      };
      await apiClient.post('/paint/items', payload);
      setShowPaintModal(false);
      showToast(`Paint Completed: ${selectedPaintItem.bom_item?.standard_part_no || 'Part'}`);
      loadData('paint', false);
    } catch (err) {
      Alert.alert('Action Failed', err.response?.data?.message || 'Could not complete paint process.');
    }
  };

  // --- ASSEMBLY ACTIONS ---
  const handleSubmitAssembly = async (part) => {
    try {
      const sideStat = part.side_stats?.[unitSideTab] || {};
      const sidePaintRecords = sideStat.paint_records || (part.paint_records || []).filter(p => p.side === unitSideTab || p.side === 'COMMON');
      const sideQcInspections = sideStat.qc_inspections || (part.qc_inspections || []).filter(q => q.side === unitSideTab || q.side === 'COMMON');

      const paintRec = sidePaintRecords.find(p => ['completed', 'assembled'].includes(p.status));
      const directQcInsp = sideQcInspections.find(q => q.destination === 'ASSEMBLY' && q.approved_quantity > 0);

      await apiClient.post('/assembly/items', {
        bom_item_id: part.id,
        paint_record_id: paintRec ? paintRec.id : null,
        qc_inspection_id: directQcInsp ? directQcInsp.id : null,
        side: unitSideTab,
        quantity: sideStat.assembly_ready || 1,
        remarks: 'Mobile Assembly Done',
      });
      showToast(`Assembly Complete: ${part.standard_part_no} (${unitSideTab})`);
      loadData('assembly', false);
    } catch (err) {
      Alert.alert('Action Failed', err.response?.data?.message || 'Could not complete assembly.');
    }
  };

  // --- BULK ACTION HANDLERS (Issue 5 & Phase 4) ---
  const [isSubmittingBulk, setIsSubmittingBulk] = useState(false);

  const handleBulkStoreReceive = async (targetItems) => {
    if (isSubmittingBulk) return;
    const itemsPayload = targetItems.map(item => ({
      bom_item_id: item.id,
      side: unitSideTab,
      received_quantity: item.side_stats?.[unitSideTab]?.pending ?? 1,
    }));

    if (!itemsPayload.length) {
      Alert.alert('No Items', 'No items selected for bulk receipt.');
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/store/bulk-receive', {
        project_id: selectedProject || targetItems[0]?.project_id,
        delivery_note_number: bulkDeliveryNote || `DN-BULK-${new Date().toISOString().slice(0, 10)}`,
        items: itemsPayload,
      });

      showToast(res.data.message || `Bulk received ${itemsPayload.length} items`);
      clearSelection();
      setShowBulkStoreReceiveModal(false);
      loadData('store', false);
    } catch (err) {
      Alert.alert('Bulk Receive Failed', err.response?.data?.message || 'Could not record bulk receipt.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkProcessReturned = async (targetItems, action) => {
    if (isSubmittingBulk) return;
    setIsSubmittingBulk(true);
    let count = 0;
    try {
      for (const item of targetItems) {
        try {
          await apiClient.post(`/store/items/${item.id}/process-returned`, {
            action,
            remarks: `Bulk processed via Mobile Store App as ${action}`,
          });
          count++;
        } catch (e) {}
      }
      showToast(`Bulk processed ${count} returned items as ${action}`);
      clearSelection();
      loadData('store', false);
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkQcArrivalAccept = async (targetItems) => {
    if (isSubmittingBulk) return;
    const receiptIds = [];
    const bomIds = [];

    for (const item of targetItems) {
      if (item.receipt_item_id) {
        receiptIds.push(item.receipt_item_id);
      } else if (item.receipt_items && item.receipt_items.length > 0) {
        const matches = item.receipt_items.filter(r => ['received', 'sent_to_qc', 'store_resident'].includes(r.status) && (r.side === unitSideTab || r.side === 'COMMON'));
        if (matches.length > 0) {
          matches.forEach(m => receiptIds.push(m.id));
        } else {
          bomIds.push(item.id);
        }
      } else if (item.id) {
        bomIds.push(item.id);
      }
    }

    if (!receiptIds.length && !bomIds.length) {
      Alert.alert('No Eligible Items', 'No pending physical arrivals found for the selected items.');
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/qc/bulk-receive', {
        receipt_item_ids: receiptIds.length ? receiptIds : undefined,
        bom_item_ids: bomIds.length ? bomIds : undefined,
        side: unitSideTab,
      });
      const count = res.data.processed_count ?? (receiptIds.length + bomIds.length);
      showToast(res.data.message || `Accepted ${count} items in QC`);
      clearSelection();
      loadData('qc', false);
    } catch (err) {
      Alert.alert('Bulk Action Failed', err.response?.data?.message || 'Could not process bulk arrival acceptance.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkQcArrivalReject = async (targetItems) => {
    Alert.alert(
      'Bulk Reject Arrival',
      `Reject physical arrival for ${targetItems.length} selected parts?\n\nThey will be sent back to Store.`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Reject & Return Store',
          style: 'destructive',
          onPress: async () => {
            if (isSubmittingBulk) return;
            setIsSubmittingBulk(true);
            let count = 0;
            try {
              for (const item of targetItems) {
                const rec = (item.receipt_items || []).find(r => ['received', 'sent_to_qc'].includes(r.status) && (r.side === unitSideTab || r.side === 'COMMON'));
                if (rec) {
                  try {
                    await apiClient.post('/qc/reject-arrival', { receipt_item_id: rec.id });
                    count++;
                  } catch (e) {}
                }
              }
              showToast(`Rejected ${count} items returned to Store`, 'error');
              clearSelection();
              loadData('qc', false);
            } finally {
              setIsSubmittingBulk(false);
            }
          }
        }
      ]
    );
  };

  const handleBulkQcInspect = async (targetItems, result, destination = null) => {
    if (isSubmittingBulk) return;
    const receiptIds = [];
    const bomIds = [];

    for (const item of targetItems) {
      const sideStat = item.side_stats?.[unitSideTab];
      const sideReceipts = sideStat?.receipt_items || (item.receipt_items || []).filter(r => r.side === unitSideTab || r.side === 'COMMON');
      const rec = sideReceipts.find(r => r.status === 'qc_received');
      if (rec) {
        receiptIds.push(rec.id);
      } else if (item.id) {
        bomIds.push(item.id);
      }
    }

    if (!receiptIds.length && !bomIds.length) {
      Alert.alert('No Eligible Items', `No pending inspection items found for selected parts on ${unitSideTab} side.`);
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/qc/bulk-inspect', {
        receipt_item_ids: receiptIds.length ? receiptIds : undefined,
        bom_item_ids: bomIds.length ? bomIds : undefined,
        side: unitSideTab,
        result,
        destination: result === 'approved' ? destination : null,
        rejection_reason: result === 'rejected' ? 'Bulk rejection (Defect/Dimensional)' : null,
        rework_reason: result === 'rework' ? 'Bulk rework required' : null,
        remarks: `Bulk QC inspection marked as ${result.toUpperCase()} (${unitSideTab})`,
      });
      const count = res.data.processed_count ?? (receiptIds.length + bomIds.length);
      showToast(res.data.message || `Processed ${count} items as ${result.toUpperCase()}`);
      clearSelection();
      setShowBulkQcDestinationModal(false);
      loadData('qc', false);
    } catch (err) {
      Alert.alert('Bulk Inspection Failed', err.response?.data?.message || 'Could not process bulk QC inspection.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkReworkAction = async (targetItems, action) => {
    if (isSubmittingBulk) return;
    const reworkIds = targetItems
      .map(item => (item.rework_records || []).find(r => (action === 'start' ? r.status === 'pending' : (r.status === 'pending' || r.status === 'in_progress')) && (r.side === unitSideTab || r.side === 'COMMON'))?.id)
      .filter(Boolean);

    if (!reworkIds.length) {
      Alert.alert('No Eligible Items', `No rework records available to ${action}.`);
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/rework/bulk-action', {
        rework_record_ids: reworkIds,
        action,
        completion_notes: bulkReworkNotes || 'Bulk rework completed.',
      });
      showToast(res.data.message || `Bulk rework ${action} completed`);
      clearSelection();
      setShowBulkReworkModal(false);
      loadData('rework', false);
    } catch (err) {
      Alert.alert('Bulk Rework Failed', err.response?.data?.message || 'Could not process bulk rework.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkPaintComplete = async (targetItems) => {
    if (isSubmittingBulk) return;
    const inspIds = targetItems
      .map(item => (item.qc_inspections || []).find(q => q.approved_quantity > 0 && (q.destination === 'PAINT' || !q.destination) && (q.side === unitSideTab || q.side === 'COMMON'))?.id)
      .filter(Boolean);

    if (!inspIds.length) {
      Alert.alert('No Eligible Items', 'No pending paint records found for the selected items.');
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/paint/bulk-complete', {
        qc_inspection_ids: inspIds,
        paint_type: bulkPaintType,
        remarks: 'Bulk Paint operation completed',
      });
      showToast(res.data.message || `Bulk paint completed for ${inspIds.length} items`);
      clearSelection();
      setShowBulkPaintModal(false);
      loadData('paint', false);
    } catch (err) {
      Alert.alert('Bulk Paint Failed', err.response?.data?.message || 'Could not process bulk paint completion.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkAssemblyComplete = async (targetItems) => {
    if (isSubmittingBulk) return;
    const assemblyPayload = targetItems.map(item => {
      const paintRec = (item.paint_records || []).find(p => ['completed', 'assembled'].includes(p.status) && (p.side === unitSideTab || p.side === 'COMMON'));
      const directQcInsp = (item.qc_inspections || []).find(q => q.destination === 'ASSEMBLY' && q.approved_quantity > 0 && (q.side === unitSideTab || q.side === 'COMMON'));

      return {
        bom_item_id: item.id,
        paint_record_id: paintRec ? paintRec.id : null,
        qc_inspection_id: directQcInsp ? directQcInsp.id : null,
        side: unitSideTab,
        quantity: item.metrics?.assembly_ready || 1,
      };
    }).filter(e => e.paint_record_id || e.qc_inspection_id);

    if (!assemblyPayload.length) {
      Alert.alert('No Eligible Items', 'No items ready for assembly found in the selection.');
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/assembly/bulk-complete', {
        items: assemblyPayload,
        remarks: 'Bulk Assembly operation completed',
      });
      showToast(res.data.message || `Bulk assembly completed for ${assemblyPayload.length} items`);
      clearSelection();
      loadData('assembly', false);
    } catch (err) {
      Alert.alert('Bulk Assembly Failed', err.response?.data?.message || 'Could not process bulk assembly completion.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  if (!token) {
    return (
      <SafeAreaView style={styles.container}>
        <StatusBar style="dark" />
        <ScrollView contentContainerStyle={styles.scrollContent} keyboardShouldPersistTaps="handled">
          <View style={styles.loginBox}>
            <Image source={require('./assets/logo.png')} style={styles.loginLogo} resizeMode="contain" />

            {errorMsg ? (
              <View style={styles.errorContainer}>
                <Text style={styles.errorText}>{errorMsg}</Text>
              </View>
            ) : null}

            <Text style={styles.label}>Server Host / IP</Text>
            <TextInput
              style={styles.input}
              value={serverHost}
              onChangeText={setServerHost}
              placeholder="e.g. 192.168.9.200:8080"
              autoCapitalize="none"
              autoCorrect={false}
            />

            <Text style={styles.label}>Email Address</Text>
            <TextInput
              style={styles.input}
              value={email}
              onChangeText={setEmail}
              placeholder="admin@sparetrack.internal"
              autoCapitalize="none"
              keyboardType="email-address"
              autoCorrect={false}
            />

            <Text style={styles.label}>Password</Text>
            <TextInput
              style={styles.input}
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              placeholder="••••••••"
            />

            <TouchableOpacity style={styles.button} onPress={handleLogin} disabled={loading}>
              {loading ? (
                <ActivityIndicator color="#ffffff" />
              ) : (
                <Text style={styles.buttonText}>Sign In to Mobile Terminal</Text>
              )}
            </TouchableOpacity>

            {/* Live OTA Update Taskbar Pill */}
            <TouchableOpacity 
              style={styles.otaUpdateBar} 
              onPress={handleCheckOtaUpdate} 
              disabled={otaChecking}
              activeOpacity={0.7}
            >
              <View style={[styles.otaDot, { backgroundColor: otaChecking ? '#f59e0b' : '#10b981' }]} />
              <Text style={styles.otaText}>
                {otaChecking ? 'Checking for updates...' : 'v2.4.0 • Phase 4 Live • Tap to Check'}
              </Text>
              {otaChecking && <ActivityIndicator size="small" color="#64748b" style={{ marginLeft: 6 }} />}
            </TouchableOpacity>
          </View>
        </ScrollView>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.container}>
      <StatusBar style="dark" />

      {/* Floating Toast Notification (Issue 4) */}
      {toast.visible && (
        <View style={[styles.toastBanner, toast.type === 'error' ? styles.toastError : styles.toastSuccess]}>
          <Text style={styles.toastText}>
            {toast.type === 'error' ? '⚠️ ' : '✓ '}{toast.message}
          </Text>
        </View>
      )}

      {/* Top Header with Logo */}
      <View style={styles.header}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, flex: 1 }}>
          <Image source={require('./assets/logo.png')} style={styles.headerLogo} resizeMode="contain" />
          <View style={{ flex: 1 }}>
            <Text style={styles.headerTitle} numberOfLines={1}>FAITH AUTOMATION</Text>
            <Text style={styles.userSubtitle} numberOfLines={1}>
              {user?.name || 'User'} • <Text style={styles.roleBadge}>{userRole}</Text>
            </Text>
          </View>
        </View>
        <TouchableOpacity style={styles.logoutBtn} onPress={handleLogout}>
          <Text style={styles.logoutBtnText}>Logout</Text>
        </TouchableOpacity>
      </View>

      {/* Navigation Tabs Bar */}
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.tabsContainer}>
        {['ADMIN', 'MANAGER'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'dashboard' && styles.activeTab]}
            onPress={() => handleTabChange('dashboard')}>
            <Text style={[styles.tabText, activeTab === 'dashboard' && styles.activeTabText]}>📊 Summary</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'STORE'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'store' && styles.activeTab]}
            onPress={() => handleTabChange('store')}>
            <Text style={[styles.tabText, activeTab === 'store' && styles.activeTabText]}>📦 Store</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'QC'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'qc' && styles.activeTab]}
            onPress={() => handleTabChange('qc')}>
            <Text style={[styles.tabText, activeTab === 'qc' && styles.activeTabText]}>🔍 QC Queue</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'REWORK'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'rework' && styles.activeTab]}
            onPress={() => handleTabChange('rework')}>
            <Text style={[styles.tabText, activeTab === 'rework' && styles.activeTabText]}>🛠️ Rework</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'PAINT'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'paint' && styles.activeTab]}
            onPress={() => handleTabChange('paint')}>
            <Text style={[styles.tabText, activeTab === 'paint' && styles.activeTabText]}>🎨 Paint</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'ASSEMBLY'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'assembly' && styles.activeTab]}
            onPress={() => handleTabChange('assembly')}>
            <Text style={[styles.tabText, activeTab === 'assembly' && styles.activeTabText]}>⚙️ Assembly</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'PURCHASE'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'purchase' && styles.activeTab]}
            onPress={() => handleTabChange('purchase')}>
            <Text style={[styles.tabText, activeTab === 'purchase' && styles.activeTabText]}>🛒 Purchase</Text>
          </TouchableOpacity>
        )}
      </ScrollView>

      {/* Search & Filter Bar - Tab-Isolated Search (Part 13) */}
      {['store', 'qc', 'paint', 'assembly', 'rework', 'purchase'].includes(activeTab) && (
        <View style={styles.searchBarContainer}>
          <TextInput
            style={[styles.searchInput, { flex: 1 }]}
            placeholder={getSearchPlaceholder()}
            placeholderTextColor="#9ca3af"
            value={currentSearchQuery}
            onChangeText={handleSearchChange}
          />
          {currentSearchQuery !== '' && (
            <TouchableOpacity style={styles.clearSearchBtn} onPress={handleClearSearch}>
              <Text style={styles.clearSearchBtnText}>✕</Text>
            </TouchableOpacity>
          )}
          {['store', 'qc'].includes(activeTab) && (
            <TouchableOpacity style={styles.filterBtn} onPress={() => setShowFilterModal(true)}>
              <Text style={styles.filterBtnText}>Filters</Text>
            </TouchableOpacity>
          )}
        </View>
      )}

      {/* Active Filter Chips */}
      {(selectedSide || selectedProject) ? (
        <View style={styles.chipsContainer}>
          {selectedSide ? (
            <TouchableOpacity style={styles.chip} onPress={() => { setSelectedSide(''); loadData(activeTab); }}>
              <Text style={styles.chipText}>Side: {selectedSide} ✕</Text>
            </TouchableOpacity>
          ) : null}
          {selectedProject ? (
            <TouchableOpacity style={styles.chip} onPress={() => { setSelectedProject(''); loadData(activeTab); }}>
              <Text style={styles.chipText}>Project Filter Active ✕</Text>
            </TouchableOpacity>
          ) : null}
        </View>
      ) : null}

      {/* Paint Tab Status Filter Buttons (Part 10) */}
      {activeTab === 'paint' && !selectedProject && (
        <View style={{ flexDirection: 'row', gap: 6, marginHorizontal: 16, marginBottom: 8 }}>
          <TouchableOpacity
            style={[styles.chipBtn, paintStatusFilter === 'all' && styles.chipBtnActive]}
            onPress={() => setPaintStatusFilter('all')}>
            <Text style={[styles.chipBtnText, paintStatusFilter === 'all' && styles.chipBtnTextActive]}>
              All ({projects.length})
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.chipBtn, paintStatusFilter === 'active' && styles.chipBtnActive]}
            onPress={() => setPaintStatusFilter('active')}>
            <Text style={[styles.chipBtnText, paintStatusFilter === 'active' && styles.chipBtnTextActive]}>
              Active ({projects.filter(p => p.eligible_qty > 0 || !p.is_complete).length})
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.chipBtn, paintStatusFilter === 'completed' && styles.chipBtnActive]}
            onPress={() => setPaintStatusFilter('completed')}>
            <Text style={[styles.chipBtnText, paintStatusFilter === 'completed' && styles.chipBtnTextActive]}>
              Completed ({projects.filter(p => p.is_complete).length})
            </Text>
          </TouchableOpacity>
        </View>
      )}

      {/* Store Sub-Tabs (Pending vs History & Revert) */}
      {activeTab === 'store' && (
        <View style={styles.subTabsContainer}>
          <TouchableOpacity
            style={[styles.subTab, storeSubTab === 'pending' && styles.activeSubTab]}
            onPress={() => { setStoreSubTab('pending'); loadData('store'); }}>
            <Text style={[styles.subTabText, storeSubTab === 'pending' && styles.activeSubTabText]}>📦 Pending Intake</Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.subTab, storeSubTab === 'history' && styles.activeSubTab]}
            onPress={() => { setStoreSubTab('history'); loadData('store'); }}>
            <Text style={[styles.subTabText, storeSubTab === 'history' && styles.activeSubTabText]}>📜 Recent Receipts</Text>
          </TouchableOpacity>
        </View>
      )}

      {/* STICKY HIERARCHY CONTEXT HEADER (Always fixed outside ScrollView while content scrolls) */}
      {selectedProject && (
        <View style={styles.hierarchyNavRow}>
          <Text style={styles.hierarchyNavTitle} numberOfLines={1} ellipsizeMode="tail">
            {hierarchyProject ? (hierarchyProject.project_code || hierarchyProject.name) : 'Project'}
            {selectedJig ? ` › JIG: ${selectedJig.jig_name}` : ''}
            {selectedUnit ? ` › ${selectedUnit.unit_no} › ${unitSideTab}` : ''}
            {selectedUnit && activeTab === 'qc' ? ` (${qcSubTab === 'arrival' ? 'Arrival' : 'Inspection'})` : ''}
          </Text>
          <TouchableOpacity
            style={styles.backLevelBtn}
            onPress={() => {
              scrollToTop(false);
              clearSelection();
              if (selectedUnit) setSelectedUnit(null);
              else if (selectedJig) setSelectedJig(null);
              else handleResetProject();
            }}>
            <Text style={styles.backLevelBtnText}>‹ Back</Text>
          </TouchableOpacity>
        </View>
      )}

      {/* Main Content Scroll View */}
      <ScrollView
        ref={mainScrollRef}
        style={styles.content}
        contentContainerStyle={[
          styles.scrollContentContainer,
          selectedItemIds.size > 0 && selectedUnit && { paddingBottom: 120 }
        ]}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}>
        {loading && !refreshing && !selectedProject && items.length === 0 && projects.length === 0 ? (
          <ActivityIndicator size="large" color="#2563eb" style={{ marginTop: 40 }} />
        ) : activeTab === 'dashboard' ? (
          <View style={styles.cardContainer}>
            <View style={[styles.card, { backgroundColor: '#2563eb' }]}>
              <Text style={styles.cardLabel}>Active Projects</Text>
              <Text style={styles.cardValue}>{summary?.total_projects || 0}</Text>
            </View>
            <View style={[styles.card, { backgroundColor: '#10b981' }]}>
              <Text style={styles.cardLabel}>Parts Received</Text>
              <Text style={styles.cardValue}>{summary?.total_received || 0}</Text>
            </View>
            <View style={[styles.card, { backgroundColor: '#f59e0b' }]}>
              <Text style={styles.cardLabel}>Awaiting QC</Text>
              <Text style={styles.cardValue}>{summary?.awaiting_qc || 0}</Text>
            </View>
            <View style={[styles.card, { backgroundColor: '#7c3aed' }]}>
              <Text style={styles.cardLabel}>Paint Active</Text>
              <Text style={styles.cardValue}>{summary?.parts_in_paint || 0}</Text>
            </View>
            <View style={[styles.card, { backgroundColor: '#db2777' }]}>
              <Text style={styles.cardLabel}>Assembly Active</Text>
              <Text style={styles.cardValue}>
                {summary?.parts_in_assembly || 0}
                <Text style={{ fontSize: 13, fontWeight: 'normal', color: '#fbcfe8' }}> ({summary?.assembly_completed || 0} Done)</Text>
              </Text>
            </View>
            <View style={[styles.card, { backgroundColor: '#ef4444' }]}>
              <Text style={styles.cardLabel}>Purchase Queue</Text>
              <Text style={styles.cardValue}>{summary?.pending_purchase || 0}</Text>
            </View>
          </View>
        ) : ['store', 'qc', 'rework', 'paint', 'assembly'].includes(activeTab) && !(activeTab === 'store' && (storeSubTab === 'history' || storeSubTab === 'returned')) ? (
          // MOBILE UNIFIED 4-LEVEL DRILLDOWN VIEW (Page-wise Search Enabled across all departments)
          <View style={styles.listContainer}>
            {/* LEVEL 1: PROJECTS GRID (when no project selected) */}
            {!selectedProject && (
              <View>
                <Text style={styles.sectionHeader}>
                  SELECT {activeTab.toUpperCase()} PROJECT ({
                    projects
                      .filter(p => {
                        if (activeTab === 'paint') {
                          if (paintStatusFilter === 'active' && (p.eligible_qty === 0 && p.is_complete)) return false;
                          if (paintStatusFilter === 'completed' && !p.is_complete) return false;
                        }
                        if (!currentSearchQuery) return true;
                        const q = currentSearchQuery.toLowerCase().trim();
                        return (p.name || '').toLowerCase().includes(q) || (p.project_code || '').toLowerCase().includes(q);
                      }).length
                  })
                </Text>
                {projects
                  .filter(p => {
                    if (activeTab === 'paint') {
                      if (paintStatusFilter === 'active' && (p.eligible_qty === 0 && p.is_complete)) return false;
                      if (paintStatusFilter === 'completed' && !p.is_complete) return false;
                    }
                    if (!currentSearchQuery) return true;
                    const q = currentSearchQuery.toLowerCase().trim();
                    return (p.name || '').toLowerCase().includes(q) || (p.project_code || '').toLowerCase().includes(q);
                  })
                  .map((proj) => (
                  <TouchableOpacity
                    key={proj.id}
                    style={[
                      styles.jigCard,
                      proj.is_complete ? styles.jigCardComplete : styles.jigCardIncomplete
                    ]}
                    onPress={() => handleSelectProject(proj.id)}>
                    <View style={styles.itemHeader}>
                      <Text style={[styles.jigName, proj.is_complete && { color: '#15803d' }]}>
                        📁 {proj.name}
                      </Text>
                      <Text style={[styles.jigBadge, proj.is_complete ? styles.jigBadgeComplete : styles.jigBadgeIncomplete]}>
                        {proj.is_complete ? '100% DONE' : `${proj.completion_pct || 0}%`}
                      </Text>
                    </View>
                    <Text style={styles.itemSubText}>
                      Project Code: {proj.project_code || 'N/A'} • Required: {proj.total_required}
                    </Text>
                    <View style={styles.progressBarBg}>
                      <View style={[styles.progressBarFill, { width: `${proj.completion_pct || 0}%`, backgroundColor: proj.is_complete ? '#16a34a' : '#2563eb' }]} />
                    </View>
                    <Text style={styles.tapExploreText}>Tap to explore JIGs inside {proj.name} ›</Text>
                  </TouchableOpacity>
                ))}
                {projects.filter(p => {
                  if (activeTab === 'paint') {
                    if (paintStatusFilter === 'active' && (p.eligible_qty === 0 && p.is_complete)) return false;
                    if (paintStatusFilter === 'completed' && !p.is_complete) return false;
                  }
                  if (!currentSearchQuery) return true;
                  const q = currentSearchQuery.toLowerCase().trim();
                  return (p.name || '').toLowerCase().includes(q) || (p.project_code || '').toLowerCase().includes(q);
                }).length === 0 && (
                  <View style={styles.emptyState}>
                    <Text style={styles.emptyStateText}>No projects match "{currentSearchQuery}".</Text>
                  </View>
                )}
              </View>
            )}

            {/* LEVEL 2-4: JIG & UNIT DRILLDOWN (when project is selected) */}
            {selectedProject && (
              <View>
                {/* LEVEL 2: JIG CARDS GRID (when no JIG selected) */}
                {!selectedJig && (
                  <View>
                    <Text style={styles.sectionHeader}>
                      ASSEMBLY JIGS ({
                        hierarchyJigs.filter(j => {
                          if (!currentSearchQuery) return true;
                          const q = currentSearchQuery.toLowerCase().trim();
                          return (j.jig_name || '').toLowerCase().includes(q);
                        }).length
                      })
                    </Text>
                    {hierarchyJigs
                      .filter(j => {
                        if (!currentSearchQuery) return true;
                        const q = currentSearchQuery.toLowerCase().trim();
                        return (j.jig_name || '').toLowerCase().includes(q);
                      })
                      .map((jig) => (
                      <TouchableOpacity
                        key={jig.jig_name}
                        style={[
                          styles.jigCard,
                          jig.is_complete ? styles.jigCardComplete : styles.jigCardIncomplete
                        ]}
                        onPress={() => setSelectedJig(jig)}>
                        <View style={styles.itemHeader}>
                          <Text style={[styles.jigName, jig.is_complete && { color: '#15803d' }]}>
                            {jig.is_complete ? '✓ ' : '⚙️ '}JIG: {jig.jig_name}
                          </Text>
                          <Text style={[styles.jigBadge, jig.is_complete ? styles.jigBadgeComplete : styles.jigBadgeIncomplete]}>
                            {jig.is_complete ? '100% DONE' : `${jig.completion_pct}%`}
                          </Text>
                        </View>
                        <Text style={styles.itemSubText}>
                          {jig.complete_units} / {jig.total_units} Units Complete • {jig.total_parts} Parts
                        </Text>
                        <View style={styles.progressBarBg}>
                          <View style={[styles.progressBarFill, { width: `${jig.completion_pct}%`, backgroundColor: jig.is_complete ? '#16a34a' : '#2563eb' }]} />
                        </View>
                        <Text style={styles.tapExploreText}>Tap to explore Units inside {jig.jig_name} ›</Text>
                      </TouchableOpacity>
                    ))}
                    {hierarchyJigs.filter(j => {
                      if (!currentSearchQuery) return true;
                      const q = currentSearchQuery.toLowerCase().trim();
                      return (j.jig_name || '').toLowerCase().includes(q);
                    }).length === 0 && (
                      <View style={styles.emptyState}>
                        <Text style={styles.emptyStateText}>No JIGs match "{currentSearchQuery}".</Text>
                      </View>
                    )}
                  </View>
                )}

                {/* LEVEL 3: UNITS LIST (when JIG selected, no Unit selected) */}
                {selectedJig && !selectedUnit && (() => {
                  const getSideEligibility = (unit, side) => {
                    if (!unit) return { eligible: false, count: 0, required: 0, received: 0, pct: 0, label: '', buttonText: '' };
                    const sideObj = unit.sides?.[side] || {};
                    const sideParts = (unit.parts || []).filter(p => p.side_stats?.[side] || p.side_stats?.COMMON);
                    const sideMetrics = sideObj.metrics || {};
                    const hasSideParts = sideParts.length > 0 || !!unit.sides?.[side];

                    const totalRequired = sideObj.total_required ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.required || p.side_stats?.COMMON?.required || 0), 0);
                    const totalReceived = sideObj.total_received ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.received || p.side_stats?.COMMON?.received || 0), 0);
                    const totalPending = sideObj.pending_quantity ?? Math.max(0, totalRequired - totalReceived);

                    let count = 0;
                    let label = '';
                    let buttonText = `Open ${side} ›`;

                    if (activeTab === 'paint') {
                      const readyQty = sideMetrics.paint_ready ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.paint_ready || p.side_stats?.COMMON?.paint_ready || 0), 0);
                      const compQty = sideMetrics.paint_completed ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.paint_completed || p.side_stats?.COMMON?.paint_completed || 0), 0);
                      count = readyQty;
                      label = `${readyQty} Ready • ${compQty} Done`;
                      buttonText = readyQty > 0 ? `Open ${side} (${readyQty} Ready) ›` : `Open ${side} ›`;
                    } else if (activeTab === 'assembly') {
                      const readyQty = sideMetrics.assembly_ready ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.assembly_ready || p.side_stats?.COMMON?.assembly_ready || 0), 0);
                      const compQty = sideMetrics.assembly_completed ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.assembly_completed || p.side_stats?.COMMON?.assembly_completed || 0), 0);
                      count = readyQty;
                      label = `${readyQty} Ready • ${compQty} Assembled`;
                      buttonText = readyQty > 0 ? `Open ${side} (${readyQty} Ready) ›` : `Open ${side} ›`;
                    } else if (activeTab === 'qc') {
                      const pendingArrival = sideMetrics.qc_pending_arrival ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.qc_pending_arrival || p.side_stats?.COMMON?.qc_pending_arrival || 0), 0);
                      const pendingInsp = sideMetrics.qc_pending_inspection ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.qc_pending_inspection || p.side_stats?.COMMON?.qc_pending_inspection || 0), 0);
                      const approved = sideMetrics.qc_approved ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.qc_approved || p.side_stats?.COMMON?.qc_approved || 0), 0);
                      if (qcSubTab === 'arrival') {
                        count = pendingArrival;
                        label = `${pendingArrival} Pending Arrival`;
                        buttonText = pendingArrival > 0 ? `Open ${side} (${pendingArrival} Arrival) ›` : `Open ${side} ›`;
                      } else {
                        count = pendingInsp;
                        label = `${pendingInsp} Pending QC • ${approved} App`;
                        buttonText = pendingInsp > 0 ? `Open ${side} (${pendingInsp} Inspect) ›` : `Open ${side} ›`;
                      }
                    } else if (activeTab === 'rework') {
                      const rewPend = sideMetrics.rework_pending ?? 0;
                      const rewProg = sideMetrics.rework_in_progress ?? 0;
                      const rewComp = sideMetrics.rework_completed ?? 0;
                      count = rewPend + rewProg;
                      label = count > 0 ? `${count} in Rework` : `${rewComp} Completed`;
                      buttonText = count > 0 ? `Open ${side} (${count}) ›` : `Open ${side} ›`;
                    } else {
                      // Store
                      count = totalPending;
                      label = `Req: ${totalRequired} • Rec: ${totalReceived}`;
                      buttonText = totalPending > 0 ? `Open ${side} (${totalPending} Pen) ›` : `Open ${side} (Done) ›`;
                    }

                    return {
                      eligible: hasSideParts,
                      count,
                      required: totalRequired,
                      received: totalReceived,
                      pct: sideObj.completion_pct ?? 0,
                      label,
                      buttonText,
                    };
                  };

                  const filteredUnits = (selectedJig.units || []).filter(unit => {
                    if (!currentSearchQuery) return true;
                    const q = currentSearchQuery.toLowerCase().trim();
                    const uNo = (unit.unit_no || '').toLowerCase();
                    const cleanQ = q.replace(/^unit\s*/i, '').trim();
                    return uNo.includes(q) || (cleanQ && uNo.includes(cleanQ));
                  });

                  return (
                    <View>
                      <Text style={styles.sectionHeader}>
                        UNITS IN JIG: {selectedJig.jig_name} ({filteredUnits.length})
                      </Text>

                      {filteredUnits.map((unit) => {
                        const lhElig = getSideEligibility(unit, 'LH');
                        const rhElig = getSideEligibility(unit, 'RH');

                        const hasLh = (unit.parts || []).some(p => p.side_stats?.LH || p.side_stats?.COMMON) || !!unit.sides?.LH;
                        const hasRh = (unit.parts || []).some(p => p.side_stats?.RH || p.side_stats?.COMMON) || !!unit.sides?.RH;
                        const showLH = hasLh || !hasRh;
                        const showRH = hasRh || !hasLh;

                        return (
                          <View
                            key={unit.unit_no}
                            style={[
                              styles.unitCard,
                              unit.is_complete ? styles.unitCardComplete : styles.unitCardIncomplete,
                              { padding: 10 }
                            ]}>
                            {/* Single Unit Header */}
                            <View style={[styles.itemHeader, { marginBottom: 8, paddingBottom: 6, borderBottomWidth: 1, borderBottomColor: '#f1f5f9' }]}>
                              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                                <Text style={[styles.unitTitle, unit.is_complete && { color: '#15803d' }]}>
                                  {unit.is_complete ? '✓ ' : '📦 '}{unit.unit_no}
                                </Text>
                              </View>
                              <Text style={[styles.unitBadge, unit.is_complete ? styles.jigBadgeComplete : styles.unitBadgePending]}>
                                {unit.is_complete ? 'COMPLETED' : `${unit.completion_pct}%`}
                              </Text>
                            </View>

                            {/* Responsive Side Panels: Single side full width or Dual side split */}
                            <View style={{ flexDirection: 'row', gap: (showLH && showRH) ? 8 : 0 }}>
                              {/* LH Touchable Section */}
                              {showLH && (
                                <TouchableOpacity
                                  style={[
                                    styles.mobileSidePanel,
                                    { flex: 1,
                                      borderColor: lhElig.eligible ? '#0ea5e9' : '#e2e8f0',
                                      backgroundColor: lhElig.eligible ? '#f0f9ff' : '#f8fafc' }
                                  ]}
                                  disabled={!lhElig.eligible}
                                  onPress={() => {
                                    scrollToTop(false);
                                    clearSelection();
                                    setSelectedUnit(unit);
                                    setUnitSideTab('LH');
                                  }}>
                                  <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                    <View style={styles.sidePillLh}>
                                      <Text style={styles.sidePillTextLh}>🔵 LH</Text>
                                    </View>
                                    <Text style={{ fontSize: 10.5, fontWeight: '700', color: '#0369a1' }}>
                                      {lhElig.pct}%
                                    </Text>
                                  </View>
                                  <Text style={{ fontSize: 11, fontWeight: '700', color: '#1e293b', marginBottom: 2 }}>
                                    {lhElig.label}
                                  </Text>
                                  <Text style={{ fontSize: 10.5, fontWeight: '700', color: lhElig.eligible ? '#0284c7' : '#94a3b8', marginTop: 6 }}>
                                    {lhElig.buttonText}
                                  </Text>
                                </TouchableOpacity>
                              )}

                              {/* RH Touchable Section */}
                              {showRH && (
                                <TouchableOpacity
                                  style={[
                                    styles.mobileSidePanel,
                                    { flex: 1,
                                      borderColor: rhElig.eligible ? '#6366f1' : '#e2e8f0',
                                      backgroundColor: rhElig.eligible ? '#eef2ff' : '#f8fafc' }
                                  ]}
                                  disabled={!rhElig.eligible}
                                  onPress={() => {
                                    scrollToTop(false);
                                    clearSelection();
                                    setSelectedUnit(unit);
                                    setUnitSideTab('RH');
                                  }}>
                                  <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                    <View style={styles.sidePillRh}>
                                      <Text style={styles.sidePillTextRh}>🔷 RH</Text>
                                    </View>
                                    <Text style={{ fontSize: 10.5, fontWeight: '700', color: '#4338ca' }}>
                                      {rhElig.pct}%
                                    </Text>
                                  </View>
                                  <Text style={{ fontSize: 11, fontWeight: '700', color: '#1e293b', marginBottom: 2 }}>
                                    {rhElig.label}
                                  </Text>
                                  <Text style={{ fontSize: 10.5, fontWeight: '700', color: rhElig.eligible ? '#4f46e5' : '#94a3b8', marginTop: 6 }}>
                                    {rhElig.buttonText}
                                  </Text>
                                </TouchableOpacity>
                              )}
                            </View>
                          </View>
                        );
                      })}

                      {filteredUnits.length === 0 && (
                        <View style={styles.emptyState}>
                          <Text style={styles.emptyStateText}>
                            {currentSearchQuery
                              ? `No units match "${currentSearchQuery}".`
                              : `No units found in this JIG.`}
                          </Text>
                        </View>
                      )}
                    </View>
                  );
                })()}

                {/* LEVEL 4: PARTS LIST (when Unit selected) */}
                {selectedUnit && (() => {
                  const visibleParts = (selectedUnit.parts || []).filter(item => {
                    const matchSide = unitSideTab === 'LH'
                      ? !!(item.side_stats?.LH || item.side_stats?.COMMON)
                      : !!(item.side_stats?.RH || item.side_stats?.COMMON);
                    if (!matchSide) return false;

                    const currentSideStats = unitSideTab === 'LH' ? (item.side_stats?.LH || item.side_stats?.COMMON) : (item.side_stats?.RH || item.side_stats?.COMMON);

                    // Store pending filter: Only show parts with remaining pending quantity
                    if (activeTab === 'store' && storeSubTab === 'pending') {
                      if (!(currentSideStats && currentSideStats.pending > 0)) return false;
                    }

                    // Department-specific subtab filter for QC: Strictly side-isolated
                    if (activeTab === 'qc') {
                      if (qcSubTab === 'arrival' && !(currentSideStats?.qc_pending_arrival > 0)) return false;
                      if (qcSubTab === 'inspection' && !(currentSideStats?.qc_pending_inspection > 0)) return false;
                    }

                    // Department-specific filter for Rework: Strictly side-isolated
                    if (activeTab === 'rework') {
                      if (!(currentSideStats && ((currentSideStats.rework_pending || 0) + (currentSideStats.rework_in_progress || 0) > 0))) return false;
                    }

                    // Department-specific filter for Paint: Strictly side-isolated
                    if (activeTab === 'paint') {
                      if (!(currentSideStats && (currentSideStats.paint_ready || 0) > 0)) return false;
                    }

                    // Department-specific filter for Assembly: Strictly side-isolated
                    if (activeTab === 'assembly') {
                      if (!(currentSideStats && (currentSideStats.assembly_ready || 0) > 0)) return false;
                    }

                    if (!currentSearchQuery) return true;
                    const q = currentSearchQuery.toLowerCase().trim();
                    return (item.standard_part_no || '').toLowerCase().includes(q) ||
                           (item.item_no || '').toLowerCase().includes(q) ||
                           (item.supplier?.name || item.supplier_name_raw || '').toLowerCase().includes(q);
                  });

                  const selectedItemsList = visibleParts.filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));

                  return (
                    <View>
                      {/* Multi-Selection Control Bar (Compact) */}
                      <View style={styles.selectionControlBar}>
                        <TouchableOpacity
                          style={styles.selectionToggleBtn}
                          onPress={() => {
                            if (isSelectionMode) {
                              clearSelection();
                            } else {
                              setIsSelectionMode(true);
                            }
                          }}>
                          <Text style={styles.selectionToggleText}>
                            {isSelectionMode ? '✕ Cancel Selection' : '☑ Multi-Select'}
                          </Text>
                        </TouchableOpacity>

                        {isSelectionMode && (
                          <View style={{ flexDirection: 'row', gap: 6 }}>
                            <TouchableOpacity
                              style={styles.selectAllBtn}
                              onPress={() => selectAllVisible(visibleParts, unitSideTab)}>
                              <Text style={styles.selectAllBtnText}>Select All ({visibleParts.length})</Text>
                            </TouchableOpacity>
                            {selectedItemIds.size > 0 && (
                              <TouchableOpacity style={styles.clearSelectBtn} onPress={clearSelection}>
                                <Text style={styles.clearSelectBtnText}>Clear ({selectedItemIds.size})</Text>
                              </TouchableOpacity>
                            )}
                          </View>
                        )}
                      </View>

                      {/* QC Operational Mode Switcher (Physical Arrival vs Quality Inspection) */}
                      {activeTab === 'qc' && (() => {
                        const getSideStat = (p) => (unitSideTab === 'LH' ? (p.side_stats?.LH || p.side_stats?.COMMON) : (p.side_stats?.RH || p.side_stats?.COMMON));
                        const arrivalCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.qc_pending_arrival || 0) > 0).length;
                        const inspectionCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.qc_pending_inspection || 0) > 0).length;

                        return (
                          <View style={styles.qcModeRow}>
                            <TouchableOpacity
                              style={[styles.qcModeBtn, qcSubTab === 'arrival' && styles.qcModeBtnActiveArrival]}
                              onPress={() => { setQcSubTab('arrival'); clearSelection(); }}>
                              <Text style={[styles.qcModeBtnText, qcSubTab === 'arrival' && styles.qcModeBtnTextActiveArrival]}>
                                📦 1. Physical Arrival ({arrivalCount})
                              </Text>
                            </TouchableOpacity>

                            <TouchableOpacity
                              style={[styles.qcModeBtn, qcSubTab === 'inspection' && styles.qcModeBtnActiveInspection]}
                              onPress={() => { setQcSubTab('inspection'); clearSelection(); }}>
                              <Text style={[styles.qcModeBtnText, qcSubTab === 'inspection' && styles.qcModeBtnTextActiveInspection]}>
                                🔬 2. Quality Inspection ({inspectionCount})
                              </Text>
                            </TouchableOpacity>
                          </View>
                        );
                      })()}

                      {/* Parts Cards (High-Density Industrial Layout) */}
                      {visibleParts.map((item) => {
                        const itemKey = `${item.id}_${unitSideTab}`;
                        const isSelected = selectedItemIds.has(itemKey);
                        const currentSideStats = unitSideTab === 'LH' ? (item.side_stats?.LH || item.side_stats?.COMMON || {}) : (item.side_stats?.RH || item.side_stats?.COMMON || {});
                        const req = currentSideStats.required ?? 0;
                        const rec = currentSideStats.received ?? 0;
                        const pen = currentSideStats.pending ?? 0;

                        return (
                          <TouchableOpacity
                            key={`part-${item.id}-side-${unitSideTab}`}
                            activeOpacity={0.85}
                            onLongPress={() => toggleSelection(item, unitSideTab)}
                            onPress={() => {
                              if (isSelectionMode) toggleSelection(item, unitSideTab);
                            }}
                            style={[
                              styles.itemCard,
                              isSelected && { borderColor: '#2563eb', borderWidth: 2, backgroundColor: '#eff6ff' }
                            ]}>
                            {/* Row 1: Part No + Side Pill + Status Badge + Checkbox */}
                            <View style={styles.itemHeader}>
                              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, flex: 1 }}>
                                {isSelectionMode && (
                                  <View style={[styles.checkboxCircle, isSelected && styles.checkboxCircleSelected]}>
                                    {isSelected && <Text style={styles.checkmarkText}>✓</Text>}
                                  </View>
                                )}
                                <Text style={styles.itemPartNo} numberOfLines={1}>{item.standard_part_no}</Text>
                                <View style={unitSideTab === 'LH' ? styles.sidePillLh : styles.sidePillRh}>
                                  <Text style={unitSideTab === 'LH' ? styles.sidePillTextLh : styles.sidePillTextRh}>
                                    {unitSideTab}
                                  </Text>
                                </View>
                              </View>
                              <Text style={styles.itemStatus}>
                                {item.is_complete ? 'FULFILLED' : 'ACTIVE'}
                              </Text>
                            </View>

                            {/* Row 2: Inline Stats & Supplier */}
                            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 2, marginBottom: 2 }}>
                              <Text style={styles.itemSubText}>
                                <Text style={{ fontWeight: '700', color: '#1e293b' }}>Req: {req}</Text> • Rec: {rec} • Pen: {pen}
                              </Text>
                              <Text style={[styles.itemSubText, { fontSize: 10, color: '#64748b', maxWidth: '45%' }]} numberOfLines={1}>
                                {item.supplier?.name || item.supplier_name_raw || 'Standard'}
                              </Text>
                            </View>

                            {/* Store Level 4 Single Action */}
                            {activeTab === 'store' && pen > 0 && !isSelectionMode && (
                              <TouchableOpacity style={styles.smallReceiveBtn} onPress={() => openReceiveModal(item, unitSideTab)}>
                                <Text style={styles.smallReceiveBtnText}>RECEIVE {unitSideTab} STOCK ({pen})</Text>
                              </TouchableOpacity>
                            )}

                            {/* QC Actions (Separated strictly by subtab & isolated by side) */}
                            {activeTab === 'qc' && !isSelectionMode && (() => {
                              const sideStat = item.side_stats?.[unitSideTab] || {};
                              const pendingArrival = sideStat.qc_pending_arrival || 0;
                              const pendingInsp = sideStat.qc_pending_inspection || 0;
                              const sideReceipts = sideStat.receipt_items || (item.receipt_items || []).filter(r => r.side === unitSideTab || r.side === 'COMMON');

                              return (
                                <View style={{ marginTop: 6 }}>
                                  {qcSubTab === 'arrival' && pendingArrival > 0 ? (
                                    <View style={{ marginTop: 2 }}>
                                      <TouchableOpacity
                                        style={[styles.actionBtn, { backgroundColor: '#10b981' }]}
                                        onPress={() => {
                                          const rec = sideReceipts.find(r => ['received', 'sent_to_qc'].includes(r.status));
                                          if (rec) handleConfirmQcPhysicalArrival(rec.id, item.standard_part_no);
                                        }}>
                                        <Text style={styles.actionBtnText}>CONFIRM PHYSICAL ARRIVAL ({pendingArrival})</Text>
                                      </TouchableOpacity>
                                    </View>
                                  ) : null}

                                  {qcSubTab === 'inspection' && pendingInsp > 0 ? (
                                    <View style={{ marginTop: 2, gap: 4 }}>
                                      <View style={{ flexDirection: 'row', gap: 6 }}>
                                        <TouchableOpacity
                                          style={[styles.actionBtn, { flex: 1, backgroundColor: '#10b981' }]}
                                          onPress={() => {
                                            const rec = sideReceipts.find(r => r.status === 'qc_received') || {
                                              id: null,
                                              bom_item_id: item.id,
                                              received_quantity: pendingInsp,
                                              bom_item: item,
                                              side: unitSideTab
                                            };
                                            openQcModal({ ...rec, bom_item_id: item.id, bom_item: item, side: unitSideTab, received_quantity: pendingInsp }, 'approved');
                                          }}>
                                          <Text style={styles.actionBtnText}>APPROVE</Text>
                                        </TouchableOpacity>

                                        <TouchableOpacity
                                          style={[styles.actionBtn, { flex: 1, backgroundColor: '#f59e0b' }]}
                                          onPress={() => {
                                            const rec = sideReceipts.find(r => r.status === 'qc_received') || {
                                              id: null,
                                              bom_item_id: item.id,
                                              received_quantity: pendingInsp,
                                              bom_item: item,
                                              side: unitSideTab
                                            };
                                            openQcModal({ ...rec, bom_item_id: item.id, bom_item: item, side: unitSideTab, received_quantity: pendingInsp }, 'rework');
                                          }}>
                                          <Text style={styles.actionBtnText}>REWORK</Text>
                                        </TouchableOpacity>

                                        <TouchableOpacity
                                          style={[styles.actionBtn, { flex: 1, backgroundColor: '#ef4444' }]}
                                          onPress={() => {
                                            const rec = sideReceipts.find(r => r.status === 'qc_received') || {
                                              id: null,
                                              bom_item_id: item.id,
                                              received_quantity: pendingInsp,
                                              bom_item: item,
                                              side: unitSideTab
                                            };
                                            openQcModal({ ...rec, bom_item_id: item.id, bom_item: item, side: unitSideTab, received_quantity: pendingInsp }, 'rejected');
                                          }}>
                                          <Text style={styles.actionBtnText}>REJECT</Text>
                                        </TouchableOpacity>
                                      </View>
                                    </View>
                                  ) : null}
                                </View>
                              );
                            })()}

                            {/* Rework Actions (Strictly side-isolated) */}
                            {activeTab === 'rework' && !isSelectionMode && (() => {
                              const sideStat = item.side_stats?.[unitSideTab] || {};
                              const rewPending = sideStat.rework_pending || 0;
                              const rewProg = sideStat.rework_in_progress || 0;
                              const rewComp = sideStat.rework_completed || 0;
                              const sideReworks = sideStat.rework_records || (item.rework_records || []).filter(r => r.side === unitSideTab || r.side === 'COMMON');

                              return (
                                <View style={{ marginTop: 6 }}>
                                  {rewPending > 0 ? (
                                    <TouchableOpacity
                                      style={[styles.actionBtn, { backgroundColor: '#f59e0b' }]}
                                      onPress={() => {
                                        const rew = sideReworks.find(r => r.status === 'pending') || { id: item.id };
                                        handleStartRework(rew.id, item.standard_part_no);
                                      }}>
                                      <Text style={styles.actionBtnText}>START REWORK ({rewPending} pcs)</Text>
                                    </TouchableOpacity>
                                  ) : null}

                                  {rewProg > 0 ? (
                                    <TouchableOpacity
                                      style={[styles.actionBtn, { backgroundColor: '#10b981', marginTop: 4 }]}
                                      onPress={() => {
                                        const rew = sideReworks.find(r => r.status === 'in_progress') || { id: item.id };
                                        openReworkModal(rew, item);
                                      }}>
                                      <Text style={styles.actionBtnText}>COMPLETE REWORK ({rewProg} pcs)</Text>
                                    </TouchableOpacity>
                                  ) : null}

                                  {rewPending === 0 && rewProg === 0 && (
                                    <Text style={{ fontSize: 11, color: '#64748b', marginTop: 3 }}>
                                      {rewComp > 0 ? `✓ ${rewComp} pcs Rework Completed` : '✓ No Active Rework'}
                                    </Text>
                                  )}
                                </View>
                              );
                            })()}

                            {/* Paint Actions (Strictly side-isolated) */}
                            {activeTab === 'paint' && !isSelectionMode && (() => {
                              const sideStat = item.side_stats?.[unitSideTab] || {};
                              const paintReady = sideStat.paint_ready || 0;
                              const paintComp = sideStat.paint_completed || 0;
                              const sideInspections = sideStat.qc_inspections || (item.qc_inspections || []).filter(q => q.side === unitSideTab || q.side === 'COMMON');
                              const insp = sideInspections.find(q => q.approved_quantity > 0 && (q.destination === 'PAINT' || !q.destination));

                              return (
                                <View style={{ marginTop: 6 }}>
                                  {paintReady > 0 && insp ? (
                                    <TouchableOpacity
                                      style={[styles.actionBtn, { backgroundColor: '#7c3aed' }]}
                                      onPress={() => {
                                        openPaintModal({
                                          ...insp,
                                          bom_item_id: item.id,
                                          bom_item: item,
                                          side: unitSideTab,
                                          approved_quantity: paintReady,
                                        });
                                      }}>
                                      <Text style={styles.actionBtnText}>COMPLETE PAINT ({paintReady} pcs)</Text>
                                    </TouchableOpacity>
                                  ) : (
                                    <Text style={{ fontSize: 11, color: '#7c3aed', fontWeight: '700', marginTop: 3 }}>
                                      {paintComp > 0 ? `✓ ${paintComp} pcs Painted` : '✓ No Paint Operations Pending'}
                                    </Text>
                                  )}
                                </View>
                              );
                            })()}

                            {/* Assembly Actions (Strictly side-isolated) */}
                            {activeTab === 'assembly' && !isSelectionMode && (() => {
                              const sideStat = item.side_stats?.[unitSideTab] || {};
                              const asmReady = sideStat.assembly_ready || 0;
                              const asmComp = sideStat.assembly_completed || 0;

                              return (
                                <View style={{ marginTop: 6 }}>
                                  {asmReady > 0 ? (
                                    <TouchableOpacity
                                      style={[styles.actionBtn, { backgroundColor: '#0d9488' }]}
                                      onPress={() => handleSubmitAssembly(item)}>
                                      <Text style={styles.actionBtnText}>MARK ASSEMBLED ({asmReady} pcs)</Text>
                                    </TouchableOpacity>
                                  ) : (
                                    <Text style={{ fontSize: 11, color: '#0d9488', fontWeight: '700', marginTop: 3 }}>
                                      {asmComp > 0 ? `✓ ${asmComp} pcs Assembled` : '✓ No Assembly Operations Pending'}
                                    </Text>
                                  )}
                                </View>
                              );
                            })()}
                          </TouchableOpacity>
                        );
                      })}

                      {visibleParts.length === 0 && (
                        <View style={[styles.emptyState, { paddingVertical: 24, alignItems: 'center' }]}>
                          {activeTab === 'qc' && qcSubTab === 'inspection' ? (
                            <>
                              <Text style={[styles.emptyStateText, { fontWeight: '700', fontSize: 15, color: '#1e293b', marginBottom: 4, textAlign: 'center' }]}>
                                No Parts Ready for Quality Inspection
                              </Text>
                              <Text style={[styles.emptyStateText, { color: '#64748b', fontSize: 12, textAlign: 'center' }]}>
                                Parts will appear here after Physical Arrival is completed.
                              </Text>
                            </>
                          ) : activeTab === 'qc' && qcSubTab === 'arrival' ? (
                            <>
                              <Text style={[styles.emptyStateText, { fontWeight: '700', fontSize: 15, color: '#1e293b', marginBottom: 4, textAlign: 'center' }]}>
                                No Pending Physical Arrivals
                              </Text>
                              <Text style={[styles.emptyStateText, { color: '#64748b', fontSize: 12, textAlign: 'center' }]}>
                                All parts for this unit have completed physical arrival.
                              </Text>
                            </>
                          ) : (
                            <Text style={styles.emptyStateText}>No active {unitSideTab} parts found for this unit{currentSearchQuery ? ` matching "${currentSearchQuery}"` : ''}.</Text>
                          )}
                          <TouchableOpacity
                            style={[styles.smallReceiveBtn, { marginTop: 14, backgroundColor: '#0284c7', paddingHorizontal: 18, paddingVertical: 8 }]}
                            onPress={() => {
                              setSelectedUnit(null);
                              clearSelection();
                            }}>
                            <Text style={styles.smallReceiveBtnText}>‹ Back to Units List</Text>
                          </TouchableOpacity>
                        </View>
                      )}
                    </View>
                  );
                })()}
              </View>
            )}
          </View>
        ) : activeTab === 'store' && storeSubTab === 'history' ? (
          // STORE RECEIPT HISTORY & REVERT VIEW
          <View style={styles.listContainer}>
            <Text style={styles.sectionHeader}>
              RECENT STORE RECEIPTS ({
                historyItems.filter(item => {
                  if (!currentSearchQuery) return true;
                  const q = currentSearchQuery.toLowerCase().trim();
                  return (item.bom_item?.standard_part_no || '').toLowerCase().includes(q) ||
                         (item.bom_item?.project?.name || '').toLowerCase().includes(q) ||
                         (item.side || '').toLowerCase().includes(q) ||
                         (item.status || '').toLowerCase().includes(q);
                }).length
              })
            </Text>
            {historyItems.filter(item => {
              if (!currentSearchQuery) return true;
              const q = currentSearchQuery.toLowerCase().trim();
              return (item.bom_item?.standard_part_no || '').toLowerCase().includes(q) ||
                     (item.bom_item?.project?.name || '').toLowerCase().includes(q) ||
                     (item.side || '').toLowerCase().includes(q) ||
                     (item.status || '').toLowerCase().includes(q);
            }).length === 0 ? (
              <View style={styles.emptyState}>
                <Text style={styles.emptyStateText}>No receipt history found{currentSearchQuery ? ` matching "${currentSearchQuery}"` : ''}.</Text>
              </View>
            ) : (
              historyItems
                .filter(item => {
                  if (!currentSearchQuery) return true;
                  const q = currentSearchQuery.toLowerCase().trim();
                  return (item.bom_item?.standard_part_no || '').toLowerCase().includes(q) ||
                         (item.bom_item?.project?.name || '').toLowerCase().includes(q) ||
                         (item.side || '').toLowerCase().includes(q) ||
                         (item.status || '').toLowerCase().includes(q);
                })
                .map((item) => (
                <View key={item.id} style={styles.itemCard}>
                  <View style={styles.itemHeader}>
                    <Text style={styles.itemPartNo}>{item.bom_item?.standard_part_no || `Item #${item.id}`}</Text>
                    <Text style={styles.itemStatus}>{(item.status || 'Received').toUpperCase()}</Text>
                  </View>
                  <Text style={styles.itemSubText}>Side: {item.side} | Qty Received: {item.received_quantity}</Text>
                  <Text style={styles.itemSubText}>Date: {new Date(item.created_at).toLocaleString()}</Text>
                  
                  {['received', 'sent_to_qc'].includes(item.status) && (
                    <TouchableOpacity style={styles.revertBtn} onPress={() => handleRevertReceipt(item)}>
                      <Text style={styles.revertBtnText}>↩️ Revert / Undo Receipt</Text>
                    </TouchableOpacity>
                  )}
                </View>
              ))
            )}
          </View>
        ) : (
          // PURCHASE QUEUE OR FALLBACK
          <View style={styles.listContainer}>
            <Text style={styles.sectionHeader}>
              PURCHASE REQUISITION QUEUE ({
                items.filter(item => {
                  if (!currentSearchQuery) return true;
                  const q = currentSearchQuery.toLowerCase().trim();
                  return (item.bom_item?.standard_part_no || '').toLowerCase().includes(q) ||
                         (item.bom_item?.project?.name || '').toLowerCase().includes(q) ||
                         (item.side || '').toLowerCase().includes(q) ||
                         (item.reason || '').toLowerCase().includes(q) ||
                         (item.status || '').toLowerCase().includes(q);
                }).length
              })
            </Text>
            {items
              .filter(item => {
                if (!currentSearchQuery) return true;
                const q = currentSearchQuery.toLowerCase().trim();
                return (item.bom_item?.standard_part_no || '').toLowerCase().includes(q) ||
                       (item.bom_item?.project?.name || '').toLowerCase().includes(q) ||
                       (item.side || '').toLowerCase().includes(q) ||
                       (item.reason || '').toLowerCase().includes(q) ||
                       (item.status || '').toLowerCase().includes(q);
              })
              .map((item, idx) => (
              <View key={item.id || idx} style={styles.itemCard}>
                <View style={styles.itemHeader}>
                  <Text style={styles.itemPartNo}>{item.bom_item?.standard_part_no || `Item #${item.id}`}</Text>
                  <Text style={styles.itemStatus}>{(item.status || 'PENDING').toUpperCase()}</Text>
                </View>
                <Text style={styles.itemSubText}>Project: {item.bom_item?.project?.name || 'N/A'}</Text>
                <Text style={styles.itemSubText}>Side: {item.side} | Qty Required: {item.quantity}</Text>
                <Text style={styles.itemSubText}>Reason: {item.reason || 'QC Rejection'}</Text>
              </View>
            ))}
            {items.filter(item => {
              if (!currentSearchQuery) return true;
              const q = currentSearchQuery.toLowerCase().trim();
              return (item.bom_item?.standard_part_no || '').toLowerCase().includes(q) ||
                     (item.bom_item?.project?.name || '').toLowerCase().includes(q) ||
                     (item.side || '').toLowerCase().includes(q) ||
                     (item.reason || '').toLowerCase().includes(q) ||
                     (item.status || '').toLowerCase().includes(q);
            }).length === 0 && (
              <View style={styles.emptyState}>
                <Text style={styles.emptyStateText}>No purchase items found{currentSearchQuery ? ` matching "${currentSearchQuery}"` : ''}.</Text>
              </View>
            )}
          </View>
        )}
      </ScrollView>

      {/* FIXED BOTTOM STICKY ACTION BAR */}
      {selectedItemIds.size > 0 && selectedUnit && (
        <View style={styles.stickyBottomActionBar}>
          <View style={styles.stickyBarHeader}>
            <Text style={styles.stickyBarCountBadge}>
              Selected: {selectedItemIds.size} {selectedItemIds.size === 1 ? 'part' : 'parts'} ({unitSideTab})
            </Text>
            <TouchableOpacity onPress={clearSelection} style={styles.stickyBarClearBtn}>
              <Text style={styles.stickyBarClearText}>✕ Clear</Text>
            </TouchableOpacity>
          </View>

          {/* Department-specific primary bulk action controls */}
          {(() => {
            const selectedItemsList = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));

            return (
              <View>
                {activeTab === 'store' && storeSubTab === 'pending' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#2563eb' }]}
                    onPress={() => {
                      setBulkDeliveryNote(`DN-${new Date().toISOString().slice(0, 10)}`);
                      setShowBulkStoreReceiveModal(true);
                    }}>
                    <Text style={styles.bulkBtnText}>RECEIVE SELECTED ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'qc' && qcSubTab === 'arrival' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#10b981' }]}
                    onPress={() => handleBulkQcArrivalAccept(selectedItemsList)}>
                    <Text style={styles.bulkBtnText}>CONFIRM ARRIVAL ({selectedItemIds.size})</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'qc' && qcSubTab === 'inspection' && (
                  <View style={{ flexDirection: 'row', gap: 6 }}>
                    <TouchableOpacity
                      style={[styles.bulkBtn, { backgroundColor: '#10b981', flex: 1 }]}
                      onPress={() => setShowBulkQcDestinationModal(true)}>
                      <Text style={styles.bulkBtnText}>APPROVE ({selectedItemIds.size})</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={[styles.bulkBtn, { backgroundColor: '#f59e0b', flex: 1 }]}
                      onPress={() => handleBulkQcInspect(selectedItemsList, 'rework')}>
                      <Text style={styles.bulkBtnText}>REWORK ({selectedItemIds.size})</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={[styles.bulkBtn, { backgroundColor: '#ef4444', flex: 1 }]}
                      onPress={() => handleBulkQcInspect(selectedItemsList, 'rejected')}>
                      <Text style={styles.bulkBtnText}>REJECT ({selectedItemIds.size})</Text>
                    </TouchableOpacity>
                  </View>
                )}

                {activeTab === 'rework' && (
                  <View style={{ flexDirection: 'row', gap: 8 }}>
                    <TouchableOpacity
                      style={[styles.bulkBtn, { backgroundColor: '#f59e0b', flex: 1 }]}
                      onPress={() => handleBulkReworkAction(selectedItemsList, 'start')}>
                      <Text style={styles.bulkBtnText}>START REWORK ({selectedItemIds.size})</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={[styles.bulkBtn, { backgroundColor: '#10b981', flex: 1 }]}
                      onPress={() => setShowBulkReworkModal(true)}>
                      <Text style={styles.bulkBtnText}>COMPLETE ALL ({selectedItemIds.size})</Text>
                    </TouchableOpacity>
                  </View>
                )}

                {activeTab === 'paint' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#7c3aed' }]}
                    onPress={() => setShowBulkPaintModal(true)}>
                    <Text style={styles.bulkBtnText}>COMPLETE PAINT ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'assembly' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#0d9488' }]}
                    onPress={() => handleBulkAssemblyComplete(selectedItemsList)}>
                    <Text style={styles.bulkBtnText}>MARK ASSEMBLED ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}
              </View>
            );
          })()}
        </View>
      )}

      {/* FILTER MODAL */}
      <Modal visible={showFilterModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Filter {activeTab.toUpperCase()} Items</Text>
            
            <Text style={styles.label}>Select Side Requirement</Text>
            <View style={{ flexDirection: 'row', gap: 6, marginBottom: 14 }}>
              {['', 'RH', 'LH', 'COMMON'].map((s) => (
                <TouchableOpacity
                  key={s}
                  style={[styles.chipBtn, selectedSide === s && styles.chipBtnActive]}
                  onPress={() => setSelectedSide(s)}>
                  <Text style={[styles.chipBtnText, selectedSide === s && styles.chipBtnTextActive]}>
                    {s || 'ALL'}
                  </Text>
                </TouchableOpacity>
              ))}
            </View>

            {projects.length > 0 && (
              <View style={{ marginBottom: 16 }}>
                <Text style={styles.label}>Select Project</Text>
                <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ flexDirection: 'row' }}>
                  <TouchableOpacity
                    style={[styles.chipBtn, selectedProject === '' && styles.chipBtnActive, { marginRight: 6 }]}
                    onPress={() => setSelectedProject('')}>
                    <Text style={[styles.chipBtnText, selectedProject === '' && styles.chipBtnTextActive]}>
                      All Projects
                    </Text>
                  </TouchableOpacity>
                  {projects.map((p) => (
                    <TouchableOpacity
                      key={p.id}
                      style={[styles.chipBtn, selectedProject === p.id && styles.chipBtnActive, { marginRight: 6 }]}
                      onPress={() => setSelectedProject(p.id)}>
                      <Text style={[styles.chipBtnText, selectedProject === p.id && styles.chipBtnTextActive]}>
                        {p.name ? (p.project_code ? `${p.name} (${p.project_code})` : p.name) : p.project_code}
                      </Text>
                    </TouchableOpacity>
                  ))}
                </ScrollView>
              </View>
            )}

            <TouchableOpacity style={styles.button} onPress={() => { setShowFilterModal(false); loadData(activeTab); }}>
              <Text style={styles.buttonText}>Apply Filters</Text>
            </TouchableOpacity>
          </View>
        </View>
      </Modal>

      {/* STORE RECEIVE CONFIRMATION MODAL */}
      <Modal visible={showReceiveModal} animationType="fade" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Confirm Store Stock Receipt</Text>
            <Text style={styles.itemPartNo}>{selectedItemForReceive?.standard_part_no}</Text>
            <Text style={styles.itemSubText}>Side: {receiveSide} | Delivery Note: {deliveryNote}</Text>

            <Text style={[styles.label, { marginTop: 12 }]}>Received Quantity</Text>
            <TextInput
              style={styles.input}
              value={receiveQty}
              onChangeText={setReceiveQty}
              keyboardType="number-pad"
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowReceiveModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.button, { flex: 1, opacity: isSubmittingReceive ? 0.5 : 1 }]}
                onPress={submitStoreReceive}
                disabled={isSubmittingReceive}
              >
                <Text style={styles.buttonText}>{isSubmittingReceive ? 'Saving...' : 'Confirm Receipt'}</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* BULK STORE RECEIVE MODAL */}
      <Modal visible={showBulkStoreReceiveModal} animationType="fade" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Bulk Stock Receipt ({selectedItemIds.size} Parts)</Text>
            <Text style={styles.itemSubText}>Side: {unitSideTab} • Automatically receives remaining pending quantities</Text>

            <Text style={[styles.label, { marginTop: 12 }]}>Delivery Note Number</Text>
            <TextInput
              style={styles.input}
              value={bulkDeliveryNote}
              onChangeText={setBulkDeliveryNote}
              placeholder="e.g. DN-2026-08-17"
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowBulkStoreReceiveModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.button, { flex: 1, backgroundColor: '#2563eb' }]}
                onPress={() => {
                  const parts = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
                  handleBulkStoreReceive(parts);
                }}>
                <Text style={styles.buttonText}>Receive ({selectedItemIds.size})</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* QC INSPECTION MODAL (Issue 3: Route Selection Paint vs Assembly) */}
      <Modal visible={showQcModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Record QC Inspection ({qcResult.toUpperCase()})</Text>
            <Text style={styles.itemPartNo}>{selectedQcItem?.bom_item?.standard_part_no}</Text>
            <Text style={styles.itemSubText}>Available: {selectedQcItem?.received_quantity || 1} pcs ({selectedQcItem?.side})</Text>

            {qcResult === 'approved' && (
              <View style={{ marginTop: 12, marginBottom: 8 }}>
                <Text style={[styles.label, { color: '#0f172a', fontWeight: '800' }]}>
                  Choose Next Processing Route (Required)
                </Text>
                <View style={{ flexDirection: 'row', gap: 8, marginTop: 4 }}>
                  <TouchableOpacity
                    style={[
                      styles.routeCard,
                      { borderColor: '#7c3aed' },
                      qcDestination === 'PAINT' && { backgroundColor: '#7c3aed' }
                    ]}
                    onPress={() => setQcDestination('PAINT')}>
                    <Text style={[
                      styles.routeCardTitle,
                      { color: '#7c3aed' },
                      qcDestination === 'PAINT' && { color: '#ffffff' }
                    ]}>
                      PAINT SHOP
                    </Text>
                    <Text style={[
                      styles.routeCardDesc,
                      qcDestination === 'PAINT' && { color: '#f3e8ff' }
                    ]}>
                      Queue for painting
                    </Text>
                  </TouchableOpacity>

                  <TouchableOpacity
                    style={[
                      styles.routeCard,
                      { borderColor: '#0d9488' },
                      qcDestination === 'ASSEMBLY' && { backgroundColor: '#0d9488' }
                    ]}
                    onPress={() => setQcDestination('ASSEMBLY')}>
                    <Text style={[
                      styles.routeCardTitle,
                      { color: '#0d9488' },
                      qcDestination === 'ASSEMBLY' && { color: '#ffffff' }
                    ]}>
                      DIRECT ASSEMBLY
                    </Text>
                    <Text style={[
                      styles.routeCardDesc,
                      qcDestination === 'ASSEMBLY' && { color: '#ccfbf1' }
                    ]}>
                      Bypass paint direct to assembly
                    </Text>
                  </TouchableOpacity>
                </View>
                {!qcDestination && (
                  <Text style={{ fontSize: 11, color: '#ef4444', marginTop: 4, fontWeight: '600' }}>
                    * Must select Paint Shop or Direct Assembly before submitting approval.
                  </Text>
                )}
              </View>
            )}

            {qcResult === 'rejected' || qcResult === 'rework' ? (
              <View>
                <Text style={[styles.label, { marginTop: 10 }]}>Reason for {qcResult.toUpperCase()}</Text>
                <TextInput
                  style={styles.input}
                  value={qcReason}
                  onChangeText={setQcReason}
                  placeholder="e.g. Surface dent, dimensional mismatch"
                />
              </View>
            ) : null}

            <Text style={styles.label}>Remarks / Inspection Notes</Text>
            <TextInput
              style={styles.input}
              value={qcRemarks}
              onChangeText={setQcRemarks}
              placeholder="Optional remarks"
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowQcModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[
                  styles.button,
                  { flex: 1, backgroundColor: qcResult === 'rejected' ? '#ef4444' : qcResult === 'rework' ? '#f59e0b' : '#10b981' }
                ]}
                onPress={submitQcInspection}>
                <Text style={styles.buttonText}>Submit QC Result</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* BULK QC APPROVAL DESTINATION MODAL (Issue 3 & 5) */}
      <Modal visible={showBulkQcDestinationModal} animationType="fade" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Bulk QC Approval Route Selection</Text>
            <Text style={styles.itemSubText}>
              Select where the {selectedItemIds.size} approved parts should proceed ({unitSideTab}):
            </Text>

            <View style={{ gap: 10, marginTop: 14 }}>
              <TouchableOpacity
                style={[styles.routeCard, { borderColor: '#7c3aed', padding: 14 }]}
                onPress={() => {
                  const parts = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
                  handleBulkQcInspect(parts, 'approved', 'PAINT');
                }}>
                <Text style={[styles.routeCardTitle, { color: '#7c3aed', fontSize: 14 }]}>
                  1. ROUTE ALL TO PAINT SHOP
                </Text>
                <Text style={styles.routeCardDesc}>
                  Pushes all selected parts into the Paint department queue.
                </Text>
              </TouchableOpacity>

              <TouchableOpacity
                style={[styles.routeCard, { borderColor: '#0d9488', padding: 14 }]}
                onPress={() => {
                  const parts = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
                  handleBulkQcInspect(parts, 'approved', 'ASSEMBLY');
                }}>
                <Text style={[styles.routeCardTitle, { color: '#0d9488', fontSize: 14 }]}>
                  2. ROUTE DIRECTLY TO ASSEMBLY
                </Text>
                <Text style={styles.routeCardDesc}>
                  Bypasses the paint station directly into the Assembly queue.
                </Text>
              </TouchableOpacity>
            </View>

            <TouchableOpacity
              style={[styles.button, { marginTop: 14, backgroundColor: '#94a3b8' }]}
              onPress={() => setShowBulkQcDestinationModal(false)}>
              <Text style={styles.buttonText}>Cancel</Text>
            </TouchableOpacity>
          </View>
        </View>
      </Modal>

      {/* PAINT COMPLETION MODAL */}
      <Modal visible={showPaintModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Complete Painting Operation</Text>
            <Text style={styles.itemPartNo}>{selectedPaintItem?.bom_item?.standard_part_no || `Item #${selectedPaintItem?.id}`}</Text>
            <Text style={styles.itemSubText}>
              Qty: {selectedPaintItem?.approved_quantity || selectedPaintItem?.quantity || 1} pcs ({selectedPaintItem?.side || 'COMMON'})
            </Text>

            <Text style={[styles.label, { marginTop: 10 }]}>Paint Type / Color Code</Text>
            <TextInput
              style={styles.input}
              value={paintType}
              onChangeText={setPaintType}
              placeholder="e.g. RAL 7035 Powder Coat"
            />

            <Text style={styles.label}>Process Notes / Remarks</Text>
            <TextInput
              style={styles.input}
              value={paintRemarks}
              onChangeText={setPaintRemarks}
              placeholder="Optional notes"
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowPaintModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#0284c7' }]} onPress={submitPaintCompletion}>
                <Text style={styles.buttonText}>Push to Assembly</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* BULK PAINT COMPLETION MODAL (Issue 5) */}
      <Modal visible={showBulkPaintModal} animationType="fade" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Bulk Complete Painting ({selectedItemIds.size} Parts)</Text>
            <Text style={styles.itemSubText}>Side: {unitSideTab}</Text>

            <Text style={[styles.label, { marginTop: 10 }]}>Paint Type / Color Code</Text>
            <TextInput
              style={styles.input}
              value={bulkPaintType}
              onChangeText={setBulkPaintType}
              placeholder="e.g. RAL 7035 Powder Coat"
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowBulkPaintModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.button, { flex: 1, backgroundColor: '#7c3aed' }]}
                onPress={() => {
                  const parts = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
                  handleBulkPaintComplete(parts);
                }}>
                <Text style={styles.buttonText}>Complete Paint ({selectedItemIds.size})</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* REWORK COMPLETION MODAL */}
      <Modal visible={showReworkModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Complete Rework Operation</Text>
            <Text style={styles.itemPartNo}>{selectedReworkItem?.bom_item?.standard_part_no || `Rework #${selectedReworkItem?.id}`}</Text>
            <Text style={styles.itemSubText}>
              Qty: {selectedReworkItem?.quantity || 1} pcs ({selectedReworkItem?.side || 'COMMON'})
            </Text>

            <Text style={[styles.label, { marginTop: 12 }]}>Work Performed / Completion Remarks</Text>
            <TextInput
              style={[styles.input, { minHeight: 70, textAlignVertical: 'top' }]}
              value={reworkNotes}
              onChangeText={setReworkNotes}
              multiline
              numberOfLines={3}
              placeholder="Describe corrective actions taken (e.g. dimensional grinding, surface re-finishing)..."
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowReworkModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#10b981' }]} onPress={submitReworkCompletion}>
                <Text style={styles.buttonText}>Return to QC</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* BULK REWORK COMPLETION MODAL (Issue 5) */}
      <Modal visible={showBulkReworkModal} animationType="fade" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Bulk Complete Rework ({selectedItemIds.size} Parts)</Text>
            <Text style={styles.itemSubText}>Side: {unitSideTab}</Text>

            <Text style={[styles.label, { marginTop: 12 }]}>Work Performed / Completion Remarks</Text>
            <TextInput
              style={[styles.input, { minHeight: 70, textAlignVertical: 'top' }]}
              value={bulkReworkNotes}
              onChangeText={setBulkReworkNotes}
              multiline
              numberOfLines={3}
              placeholder="Describe corrective actions taken..."
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowBulkReworkModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.button, { flex: 1, backgroundColor: '#10b981' }]}
                onPress={() => {
                  const parts = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
                  handleBulkReworkAction(parts, 'complete');
                }}>
                <Text style={styles.buttonText}>Complete & Return QC</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

export default App;
registerRootComponent(App);

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f8fafc',
    paddingTop: RNStatusBar.currentHeight || 0,
  },
  scrollContent: {
    flexGrow: 1,
    justifyContent: 'center',
    padding: 20,
  },
  loginBox: {
    backgroundColor: '#ffffff',
    padding: 24,
    borderRadius: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.05,
    shadowRadius: 8,
    elevation: 3,
  },
  loginLogo: {
    width: 220,
    height: 48,
    alignSelf: 'center',
    marginBottom: 12,
  },
  headerLogo: {
    width: 92,
    height: 24,
  },
  title: {
    fontSize: 24,
    fontWeight: 'bold',
    color: '#0f172a',
    textAlign: 'center',
  },
  subtitle: {
    fontSize: 13,
    color: '#64748b',
    textAlign: 'center',
    marginBottom: 24,
  },
  errorContainer: {
    backgroundColor: '#fef2f2',
    borderColor: '#fca5a5',
    borderWidth: 1,
    borderRadius: 8,
    padding: 12,
    marginBottom: 16,
  },
  errorText: {
    color: '#991b1b',
    fontSize: 12,
  },
  label: {
    fontSize: 13,
    fontWeight: '600',
    color: '#334155',
    marginBottom: 6,
  },
  input: {
    backgroundColor: '#f8fafc',
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 8,
    padding: 12,
    marginBottom: 12,
    fontSize: 15,
    color: '#0f172a',
  },
  button: {
    backgroundColor: '#2563eb',
    padding: 12,
    borderRadius: 8,
    alignItems: 'center',
  },
  buttonText: {
    color: '#ffffff',
    fontSize: 14,
    fontWeight: 'bold',
  },
  otaUpdateBar: {
    marginTop: 14,
    paddingVertical: 7,
    paddingHorizontal: 12,
    backgroundColor: '#f1f5f9',
    borderRadius: 18,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  otaDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    marginRight: 6,
  },
  otaText: {
    fontSize: 11,
    fontWeight: '600',
    color: '#475569',
  },
  header: {
    paddingHorizontal: 12,
    paddingVertical: 7,
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderColor: '#e2e8f0',
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  headerTitle: {
    fontSize: 15,
    fontWeight: 'bold',
    color: '#0f172a',
  },
  userSubtitle: {
    fontSize: 11,
    color: '#64748b',
    marginTop: 1,
  },
  roleBadge: {
    color: '#2563eb',
    fontWeight: 'bold',
  },
  logoutBtn: {
    backgroundColor: '#fef2f2',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 4,
    borderWidth: 1,
    borderColor: '#fca5a5',
  },
  logoutBtnText: {
    color: '#ef4444',
    fontWeight: 'bold',
    fontSize: 11,
  },
  tabsContainer: {
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderColor: '#e2e8f0',
    maxHeight: 38,
    paddingHorizontal: 6,
  },
  tab: {
    paddingHorizontal: 10,
    paddingVertical: 6,
    marginRight: 2,
  },
  activeTab: {
    borderBottomWidth: 2,
    borderBottomColor: '#2563eb',
  },
  tabText: {
    fontSize: 11.5,
    color: '#64748b',
    fontWeight: '600',
  },
  activeTabText: {
    color: '#2563eb',
    fontWeight: 'bold',
  },
  subTabsContainer: {
    flexDirection: 'row',
    backgroundColor: '#f1f5f9',
    padding: 2,
    marginHorizontal: 10,
    marginTop: 4,
    marginBottom: 2,
    borderRadius: 6,
  },
  subTab: {
    flex: 1,
    paddingVertical: 5,
    alignItems: 'center',
    borderRadius: 4,
  },
  activeSubTab: {
    backgroundColor: '#ffffff',
    shadowColor: '#000',
    shadowOpacity: 0.05,
    shadowRadius: 2,
    elevation: 1,
  },
  subTabText: {
    fontSize: 11,
    fontWeight: '600',
    color: '#64748b',
  },
  activeSubTabText: {
    color: '#2563eb',
    fontWeight: 'bold',
  },
  searchBarContainer: {
    flexDirection: 'row',
    paddingHorizontal: 10,
    paddingTop: 5,
    paddingBottom: 2,
    gap: 6,
  },
  searchInput: {
    flex: 1,
    backgroundColor: '#ffffff',
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 6,
    paddingHorizontal: 10,
    paddingVertical: 4,
    fontSize: 12.5,
    height: 32,
  },
  filterBtn: {
    backgroundColor: '#e2e8f0',
    paddingHorizontal: 8,
    height: 32,
    justifyContent: 'center',
    borderRadius: 6,
  },
  clearSearchBtn: {
    backgroundColor: '#fee2e2',
    paddingHorizontal: 8,
    height: 32,
    justifyContent: 'center',
    borderRadius: 6,
  },
  clearSearchBtnText: {
    fontSize: 12,
    fontWeight: 'bold',
    color: '#ef4444',
  },
  filterBtnText: {
    fontSize: 11.5,
    fontWeight: 'bold',
    color: '#334155',
  },
  chipsContainer: {
    flexDirection: 'row',
    paddingHorizontal: 10,
    paddingTop: 3,
    paddingBottom: 1,
    gap: 4,
  },
  chip: {
    backgroundColor: '#eff6ff',
    borderColor: '#93c5fd',
    borderWidth: 1,
    borderRadius: 10,
    paddingHorizontal: 8,
    paddingVertical: 2,
  },
  chipText: {
    color: '#2563eb',
    fontSize: 10,
    fontWeight: '600',
  },
  content: {
    paddingHorizontal: 8,
    paddingVertical: 6,
  },
  cardContainer: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'space-between',
  },
  card: {
    width: '48%',
    padding: 12,
    borderRadius: 8,
    marginBottom: 8,
  },
  cardLabel: {
    color: 'rgba(255,255,255,0.85)',
    fontSize: 10,
    fontWeight: '700',
    textTransform: 'uppercase',
  },
  cardValue: {
    color: '#ffffff',
    fontSize: 22,
    fontWeight: 'bold',
    marginTop: 2,
  },
  listContainer: {
    paddingBottom: 20,
  },
  sectionHeader: {
    fontSize: 11,
    fontWeight: 'bold',
    color: '#64748b',
    marginBottom: 6,
    letterSpacing: 0.5,
  },
  hierarchyNavRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: '#ffffff',
    marginHorizontal: 10,
    marginTop: 4,
    marginBottom: 4,
    paddingHorizontal: 8,
    paddingVertical: 5,
    borderRadius: 6,
    borderWidth: 1,
    borderColor: '#cbd5e1',
    shadowColor: '#000',
    shadowOpacity: 0.05,
    shadowRadius: 2,
    elevation: 2,
  },
  hierarchyNavTitle: {
    fontSize: 11,
    fontWeight: '700',
    color: '#1e293b',
    flex: 1,
  },
  backLevelBtn: {
    backgroundColor: '#2563eb',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 4,
    marginLeft: 6,
  },
  backLevelBtnText: {
    color: '#ffffff',
    fontSize: 10.5,
    fontWeight: '700',
  },
  jigCard: {
    padding: 8,
    borderRadius: 6,
    marginBottom: 5,
    borderWidth: 1.5,
  },
  jigCardIncomplete: {
    backgroundColor: '#ffffff',
    borderColor: '#e2e8f0',
  },
  jigCardComplete: {
    backgroundColor: '#f0fdf4',
    borderColor: '#22c55e',
  },
  jigName: {
    fontSize: 12.5,
    fontWeight: '700',
    color: '#1e293b',
    flex: 1,
  },
  jigBadge: {
    fontSize: 9,
    fontWeight: '800',
    paddingHorizontal: 5,
    paddingVertical: 1.5,
    borderRadius: 3,
    marginLeft: 6,
  },
  jigBadgeComplete: {
    backgroundColor: '#22c55e',
    color: '#ffffff',
  },
  jigBadgeIncomplete: {
    backgroundColor: '#2563eb',
    color: '#ffffff',
  },
  unitCard: {
    padding: 8,
    borderRadius: 6,
    marginBottom: 5,
    borderWidth: 1.5,
  },
  unitCardIncomplete: {
    backgroundColor: '#ffffff',
    borderColor: '#cbd5e1',
  },
  unitCardComplete: {
    backgroundColor: '#f0fdf4',
    borderColor: '#22c55e',
  },
  mobileSidePanel: {
    flex: 1,
    padding: 6,
    borderRadius: 5,
    borderWidth: 1,
  },
  unitTitle: {
    fontSize: 12.5,
    fontWeight: '700',
    color: '#1e293b',
  },
  unitBadge: {
    fontSize: 9,
    fontWeight: '800',
    paddingHorizontal: 5,
    paddingVertical: 1.5,
    borderRadius: 3,
  },
  unitBadgePending: {
    backgroundColor: '#f59e0b',
    color: '#ffffff',
  },
  progressBarBg: {
    height: 4,
    backgroundColor: '#e2e8f0',
    borderRadius: 2,
    marginTop: 4,
    marginBottom: 3,
    overflow: 'hidden',
  },
  progressBarFill: {
    height: '100%',
    borderRadius: 2,
  },
  tapExploreText: {
    fontSize: 10,
    fontWeight: '700',
    color: '#2563eb',
    marginTop: 2,
  },
  emptyState: {
    backgroundColor: '#ffffff',
    padding: 16,
    borderRadius: 6,
    alignItems: 'center',
  },
  emptyStateText: {
    color: '#94a3b8',
    fontSize: 12,
  },
  itemCard: {
    backgroundColor: '#ffffff',
    padding: 6,
    borderRadius: 5,
    marginBottom: 4,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  itemHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 2,
  },
  itemPartNo: {
    fontSize: 12,
    fontWeight: 'bold',
    color: '#0f172a',
    flex: 1,
    marginRight: 6,
  },
  itemStatus: {
    fontSize: 9,
    fontWeight: 'bold',
    color: '#2563eb',
    backgroundColor: '#eff6ff',
    paddingHorizontal: 4,
    paddingVertical: 1,
    borderRadius: 3,
  },
  itemSubText: {
    fontSize: 10.5,
    color: '#64748b',
    marginTop: 1,
  },
  actionBtn: {
    paddingVertical: 4.5,
    paddingHorizontal: 6,
    borderRadius: 4,
    alignItems: 'center',
  },
  actionBtnText: {
    color: '#ffffff',
    fontWeight: '800',
    fontSize: 10,
    letterSpacing: 0.2,
  },
  revertBtn: {
    backgroundColor: '#fef2f2',
    borderColor: '#fca5a5',
    borderWidth: 1,
    padding: 5,
    borderRadius: 4,
    marginTop: 4,
    alignItems: 'center',
  },
  revertBtnText: {
    color: '#ef4444',
    fontWeight: 'bold',
    fontSize: 10.5,
  },
  statsRow: {
    marginTop: 3,
    gap: 4,
  },
  sideCardBox: {
    backgroundColor: '#f8fafc',
    padding: 5,
    borderRadius: 4,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  statBadge: {
    color: '#334155',
    fontSize: 10.5,
    fontWeight: '600',
  },
  smallReceiveBtn: {
    backgroundColor: '#2563eb',
    paddingVertical: 4.5,
    paddingHorizontal: 6,
    borderRadius: 4,
    marginTop: 3,
    alignItems: 'center',
  },
  smallReceiveBtnText: {
    color: '#ffffff',
    fontWeight: 'bold',
    fontSize: 10,
  },
  swipeLegendText: {
    textAlign: 'center',
    fontSize: 10.5,
    color: '#94a3b8',
    fontWeight: '600',
  },
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.5)',
    justifyContent: 'center',
    padding: 16,
  },
  modalBox: {
    backgroundColor: '#ffffff',
    borderRadius: 10,
    padding: 16,
  },
  modalTitle: {
    fontSize: 16,
    fontWeight: 'bold',
    color: '#0f172a',
    marginBottom: 10,
  },
  chipBtn: {
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 5,
    backgroundColor: '#f1f5f9',
  },
  chipBtnActive: {
    backgroundColor: '#2563eb',
  },
  chipBtnText: {
    fontSize: 11,
    fontWeight: 'bold',
    color: '#475569',
  },
  chipBtnTextActive: {
    color: '#ffffff',
  },
  sidePillLh: {
    backgroundColor: '#e0f2fe',
    borderWidth: 1,
    borderColor: '#38bdf8',
    paddingHorizontal: 5,
    paddingVertical: 1.5,
    borderRadius: 4,
  },
  sidePillTextLh: {
    color: '#0369a1',
    fontWeight: '800',
    fontSize: 9.5,
  },
  sidePillRh: {
    backgroundColor: '#dbeafe',
    borderWidth: 1,
    borderColor: '#60a5fa',
    paddingHorizontal: 5,
    paddingVertical: 1.5,
    borderRadius: 4,
  },
  sidePillTextRh: {
    color: '#1d4ed8',
    fontWeight: '800',
    fontSize: 9.5,
  },
  qcModeRow: {
    flexDirection: 'row',
    gap: 6,
    marginBottom: 8,
  },
  qcModeBtn: {
    flex: 1,
    paddingVertical: 7,
    paddingHorizontal: 8,
    backgroundColor: '#f8fafc',
    borderRadius: 6,
    alignItems: 'center',
    borderWidth: 1.5,
    borderColor: '#e2e8f0',
  },
  qcModeBtnActiveArrival: {
    backgroundColor: '#ecfdf5',
    borderColor: '#10b981',
  },
  qcModeBtnActiveInspection: {
    backgroundColor: '#eff6ff',
    borderColor: '#2563eb',
  },
  qcModeBtnText: {
    fontSize: 11.5,
    fontWeight: '700',
    color: '#64748b',
  },
  qcModeBtnTextActiveArrival: {
    color: '#047857',
    fontWeight: '800',
  },
  qcModeBtnTextActiveInspection: {
    color: '#1d4ed8',
    fontWeight: '800',
  },
  sideSwitchRow: {
    flexDirection: 'row',
    gap: 6,
    marginBottom: 8,
  },
  sideSwitchBtn: {
    flex: 1,
    paddingVertical: 7,
    paddingHorizontal: 8,
    backgroundColor: '#f1f5f9',
    borderRadius: 6,
    alignItems: 'center',
    borderWidth: 1.5,
    borderColor: '#e2e8f0',
  },
  sideSwitchBtnActiveLh: {
    backgroundColor: '#e0f2fe',
    borderColor: '#0284c7',
  },
  sideSwitchBtnActiveRh: {
    backgroundColor: '#dbeafe',
    borderColor: '#2563eb',
  },
  sideSwitchText: {
    fontSize: 11.5,
    fontWeight: '700',
    color: '#64748b',
  },
  sideSwitchTextActiveLh: {
    color: '#0369a1',
    fontWeight: '800',
  },
  sideSwitchTextActiveRh: {
    color: '#1d4ed8',
    fontWeight: '800',
  },
  // Toast Notification Styles (Issue 4)
  toastBanner: {
    paddingVertical: 9,
    paddingHorizontal: 16,
    borderRadius: 8,
    marginHorizontal: 12,
    marginTop: 6,
    marginBottom: 4,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.1,
    shadowRadius: 4,
    elevation: 3,
  },
  toastSuccess: {
    backgroundColor: '#059669',
  },
  toastError: {
    backgroundColor: '#dc2626',
  },
  toastText: {
    color: '#ffffff',
    fontWeight: 'bold',
    fontSize: 12.5,
    textAlign: 'center',
  },
  // Multi-Selection Control Bar Styles (Issue 5)
  selectionControlBar: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 8,
    backgroundColor: '#f8fafc',
    padding: 6,
    borderRadius: 6,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  selectionToggleBtn: {
    paddingVertical: 5,
    paddingHorizontal: 8,
    backgroundColor: '#e2e8f0',
    borderRadius: 4,
  },
  selectionToggleText: {
    fontSize: 11.5,
    fontWeight: '700',
    color: '#334155',
  },
  selectAllBtn: {
    paddingVertical: 5,
    paddingHorizontal: 8,
    backgroundColor: '#dbeafe',
    borderRadius: 4,
  },
  selectAllBtnText: {
    fontSize: 11.5,
    fontWeight: 'bold',
    color: '#1d4ed8',
  },
  clearSelectBtn: {
    paddingVertical: 5,
    paddingHorizontal: 8,
    backgroundColor: '#fee2e2',
    borderRadius: 4,
  },
  clearSelectBtnText: {
    fontSize: 11.5,
    fontWeight: 'bold',
    color: '#ef4444',
  },
  checkboxCircle: {
    width: 20,
    height: 20,
    borderRadius: 10,
    borderWidth: 2,
    borderColor: '#94a3b8',
    backgroundColor: '#ffffff',
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkboxCircleSelected: {
    borderColor: '#2563eb',
    backgroundColor: '#2563eb',
  },
  checkmarkText: {
    color: '#ffffff',
    fontSize: 12,
    fontWeight: 'bold',
    marginTop: -2,
  },
  // Fixed Sticky Bottom Action Bar
  stickyBottomActionBar: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    backgroundColor: '#0f172a',
    borderTopLeftRadius: 16,
    borderTopRightRadius: 16,
    paddingHorizontal: 16,
    paddingTop: 12,
    paddingBottom: Platform.OS === 'ios' ? 32 : 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: -4 },
    shadowOpacity: 0.3,
    shadowRadius: 10,
    elevation: 24,
    zIndex: 9999,
    borderTopWidth: 1,
    borderLeftWidth: 1,
    borderRightWidth: 1,
    borderColor: '#334155',
  },
  stickyBarHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 8,
  },
  stickyBarCountBadge: {
    color: '#ffffff',
    fontSize: 13.5,
    fontWeight: '700',
    letterSpacing: 0.2,
  },
  stickyBarClearBtn: {
    backgroundColor: 'rgba(255, 255, 255, 0.15)',
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 12,
  },
  stickyBarClearText: {
    color: '#cbd5e1',
    fontSize: 11.5,
    fontWeight: '600',
  },
  // Floating Bulk Action Bar (Legacy Support)
  floatingBulkBar: {
    backgroundColor: '#0f172a',
    borderRadius: 12,
    padding: 12,
    marginTop: 12,
    marginBottom: 20,
    shadowColor: '#000',
    shadowOpacity: 0.25,
    shadowRadius: 10,
    elevation: 8,
    borderWidth: 1,
    borderColor: '#334155',
  },
  floatingBulkText: {
    color: '#f8fafc',
    fontWeight: 'bold',
    fontSize: 13,
    textAlign: 'center',
  },
  bulkBtn: {
    paddingVertical: 10,
    paddingHorizontal: 12,
    borderRadius: 7,
    alignItems: 'center',
    justifyContent: 'center',
  },
  bulkBtnText: {
    color: '#ffffff',
    fontWeight: 'bold',
    fontSize: 12.5,
  },
  // Route Selection Cards (Issue 3)
  routeCard: {
    flex: 1,
    padding: 10,
    borderRadius: 8,
    borderWidth: 2,
    backgroundColor: '#f8fafc',
    alignItems: 'center',
  },
  routeCardTitle: {
    fontWeight: 'bold',
    fontSize: 12,
    marginBottom: 2,
    textAlign: 'center',
  },
  routeCardDesc: {
    fontSize: 10,
    color: '#64748b',
    textAlign: 'center',
  },
});
