// ═══════════════════════════════════════════════════════════════════
// Core Services Barrel
// ═══════════════════════════════════════════════════════════════════

export { ThemeService } from './services/theme.service';
export { StorageService } from './services/storage.service';
export {
  AuthService,
  NexoraUser,
  NexoraRole,
  NexoraDepartment,
  LoginCredentials,
  RegisterPayload,
  AuthErrorInfo,
  isSafeInternalUrl,
  mapAuthError,
} from './services/auth.service';
export { NavigationService } from './services/navigation.service';
export { CommandPaletteService } from './services/command-palette.service';
export { AppStateService } from './services/app-state.service';
export { ApiService, ApiResponse, Asset, Category, Location, User, QrMetadata } from './services/api.service';
export {
  NotificationService,
  NotificationInboxService,
  NexoraNotification,
  NotificationData,
  NotificationPagination,
  NotificationListFilters,
  NotificationErrorInfo,
  NotificationTypeMeta,
  NotificationTypeOption,
  NotificationTypeGroup,
  KnownNotificationType,
  NOTIFICATION_TYPE_META,
  notificationTypeMeta,
  notificationTypeGroups,
  formatNotificationTime,
  mapNotificationError,
} from './services/notification.service';
export {
  NotificationNavigationService,
  NotificationNavigationTarget,
  readSelectedId,
} from './services/notification-navigation.service';
