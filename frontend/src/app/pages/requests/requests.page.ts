import { HttpErrorResponse } from '@angular/common/http';

import { Component, inject, signal, computed, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormGroup, FormControl, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { IonIcon } from '@ionic/angular';

import {
  TicketService,
  Ticket,
  TicketCategory,
  TicketComment,
  TicketHistory,
  TicketStatus,
  TicketPriority,
  TicketAction,
  TicketPayload,
  TicketUpdatePayload,
  TicketCategoryPayload,
  TicketCommentPayload,
  TicketListFilters,
  mapTicketError,
  TicketErrorInfo,
} from '../../core/services/ticket.service';
import { AssetService, ManagedUser } from '../../core/services/asset.service';
import { AuthorizationService } from '../../core/services/authorization.service';
import { CommandPaletteService } from '../../core/services/command-palette.service';

import { NxSearchComponent } from '../../shared/components/nx-search/nx-search.component';
import { NxBadgeComponent } from '../../shared/components/nx-badge/nx-badge.component';
import { NxButtonComponent } from '../../shared/components/nx-button/nx-button.component';
import { NxPanelComponent } from '../../shared/components/nx-panel/nx-panel.component';
import { NxEmptyStateComponent } from '../../shared/components/nx-empty-state/nx-empty-state.component';
import { NxLoadingStateComponent } from '../../shared/components/nx-loading-state/nx-loading-state.component';
import { NxErrorStateComponent } from '../../shared/components/nx-error-state/nx-error-state.component';
import { NxConfirmDialogComponent } from '../../shared/components/nx-confirm-dialog/nx-confirm-dialog.component';
import { NxHasPermissionDirective } from '../../shared/components/nx-has-permission/nx-has-permission.directive';
import { NxToastService } from '../../shared/components/nx-toast/nx-toast.component';

const SEARCH_DEBOUNCE_MS = 300;
const COMMENT_MAX_LENGTH = 5000;

type BadgeStatus = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

const STATUS_LABELS: Record<TicketStatus, string> = {
  OPEN: 'Open',
  IN_PROGRESS: 'In Progress',
  RESOLVED: 'Resolved',
  CLOSED: 'Closed',
};

const STATUS_BADGE: Record<TicketStatus, BadgeStatus> = {
  OPEN: 'info',
  IN_PROGRESS: 'warning',
  RESOLVED: 'success',
  CLOSED: 'neutral',
};

const PRIORITY_LABELS: Record<TicketPriority, string> = {
  LOW: 'Low',
  MEDIUM: 'Medium',
  HIGH: 'High',
  URGENT: 'Urgent',
};

const PRIORITY_BADGE: Record<TicketPriority, BadgeStatus> = {
  LOW: 'neutral',
  MEDIUM: 'info',
  HIGH: 'warning',
  URGENT: 'danger',
};

/** Next status per current status — mirrors the backend TicketService workflow. */
const STATUS_TRANSITIONS: Record<TicketStatus, TicketStatus> = {
  OPEN: 'IN_PROGRESS',
  IN_PROGRESS: 'RESOLVED',
  RESOLVED: 'CLOSED',
  CLOSED: 'OPEN',
};

const STATUS_ACTION_LABELS: Record<TicketStatus, string> = {
  OPEN: 'Start Work',
  IN_PROGRESS: 'Resolve',
  RESOLVED: 'Close',
  CLOSED: 'Reopen',
};

const ACTION_LABELS: Record<TicketAction, string> = {
  CREATED: 'Request created',
  UPDATED: 'Request updated',
  STATUS_CHANGED: 'Status changed',
  ASSIGNMENT_CHANGED: 'Assignment changed',
};

const SORT_OPTIONS: Array<{ value: string; label: string }> = [
  { value: 'created_desc', label: 'Newest first' },
  { value: 'created_asc', label: 'Oldest first' },
  { value: 'updated_desc', label: 'Recently updated' },
  { value: 'ticket_number_desc', label: 'Ticket number' },
];

/** A single confirm-dialog request with the action to run on confirm. */
interface ConfirmRequest {
  title: string;
  message: string;
  confirmLabel: string;
  confirmVariant: 'primary' | 'danger' | 'secondary';
  action: () => void;
}

/**
 * RequestsPage — Helpdesk / Request Management workspace.
 *
 * A permission-aware, master-detail workspace over ticket categories and the
 * ticket lifecycle. Ticket status and assignee are always rendered from the
 * backend, never derived locally. Staff (`view_tickets`) see their own
 * requests and public comments; agents (`manage_tickets` or `assign_tickets`)
 * see everything and may act on status, assignment, internal notes, and
 * category management. Mutations are gated by AuthorizationService.
 */
@Component({
  selector: 'app-requests',
  standalone: true,
  imports: [
    CommonModule,
    ReactiveFormsModule,
    IonIcon,
    NxSearchComponent,
    NxBadgeComponent,
    NxButtonComponent,
    NxPanelComponent,
    NxEmptyStateComponent,
    NxLoadingStateComponent,
    NxErrorStateComponent,
    NxConfirmDialogComponent,
    NxHasPermissionDirective,
  ],
  templateUrl: './requests.page.html',
  styleUrl: './requests.page.scss',
})
export class RequestsPage implements OnInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly ticketService = inject(TicketService);
  private readonly assetService = inject(AssetService);
  private readonly authorization = inject(AuthorizationService);
  private readonly palette = inject(CommandPaletteService);
  private readonly toast = inject(NxToastService);

  readonly STATUS_LABEL: Record<string, string> = STATUS_LABELS;
  readonly PRIORITY_LABEL: Record<string, string> = PRIORITY_LABELS;
  readonly ACTION_LABEL: Record<string, string> = ACTION_LABELS;
  readonly SORTS: Array<{ value: string; label: string }> = SORT_OPTIONS;

  // ─── View state ───────────────────────────────────────────────────────────

  readonly activeTab = signal<'requests' | 'categories'>('requests');
  readonly skeletonRows = [1, 2, 3, 4, 5];
  readonly perPage = 15;

  // ─── Permissions ──────────────────────────────────────────────────────────

  readonly canCreateTicket = computed(() => this.authorization.hasPermission('view_tickets'));
  readonly hasManageTickets = computed(() => this.authorization.hasPermission('manage_tickets'));
  readonly hasAssignTickets = computed(() => this.authorization.hasPermission('assign_tickets'));
  /** Agents hold `manage_tickets` or `assign_tickets` (backend `isAgent`). */
  readonly isAgent = computed(() => this.hasManageTickets() || this.hasAssignTickets());

  // ─── Summary strip ────────────────────────────────────────────────────────

  readonly ticketsTotal = signal(0);
  readonly categoriesTotal = signal(0);

  // ─── Ticket list (master) ─────────────────────────────────────────────────

  readonly tickets = signal<Ticket[]>([]);
  readonly currentPage = signal(1);
  readonly lastPage = signal(1);
  readonly ticketsLoading = signal(true);
  readonly ticketsError = signal<TicketErrorInfo | null>(null);
  readonly searchQuery = signal('');
  readonly statusFilter = signal<TicketStatus | ''>('');
  readonly priorityFilter = signal<TicketPriority | ''>('');
  readonly categoryFilter = signal<number | ''>('');
  readonly assigneeFilter = signal<number | ''>('');
  readonly sortOption = signal('created_desc');
  readonly categories = signal<TicketCategory[]>([]);
  readonly assignees = signal<ManagedUser[]>([]);

  readonly activeFilterCount = computed(() => {
    let count = 0;
    if (this.searchQuery()) count++;
    if (this.statusFilter()) count++;
    if (this.priorityFilter()) count++;
    if (this.categoryFilter()) count++;
    if (this.assigneeFilter()) count++;
    return count;
  });

  // ─── Ticket detail ────────────────────────────────────────────────────────

  readonly selectedTicketId = signal<number | null>(null);
  readonly detailTicket = signal<Ticket | null>(null);
  readonly detailLoading = signal(false);
  readonly detailError = signal<TicketErrorInfo | null>(null);

  readonly selectedTicket = computed(() => {
    const id = this.selectedTicketId();
    if (id === null) return null;
    return this.detailTicket();
  });

  // ─── Comments ─────────────────────────────────────────────────────────────

  readonly comments = signal<TicketComment[]>([]);
  readonly commentsLoading = signal(false);
  readonly commentsError = signal<TicketErrorInfo | null>(null);
  readonly isCommentSubmitting = signal(false);
  readonly commentErrors = signal<Record<string, string>>({});
  readonly commentMessage = signal('');

  readonly isInternalNoteAvailable = computed(() => this.hasManageTickets());

  commentForm = new FormGroup({
    comment: new FormControl('', [Validators.required, Validators.maxLength(COMMENT_MAX_LENGTH)]),
    is_internal: new FormControl(false),
  });

  // ─── Assignment ───────────────────────────────────────────────────────────

  readonly isAssignFormOpen = signal(false);
  readonly assignTarget = signal<Ticket | null>(null);
  readonly isAssignSubmitting = signal(false);
  readonly assignErrors = signal<Record<string, string>>({});
  readonly assignMessage = signal('');

  assigneeForm = new FormGroup({
    user_id: new FormControl<number | null>(null, Validators.required),
  });

  // ─── History timeline ─────────────────────────────────────────────────────

  readonly history = signal<TicketHistory[]>([]);
  readonly historyLoading = signal(false);
  readonly historyError = signal<TicketErrorInfo | null>(null);

  // ─── Categories tab (management) ──────────────────────────────────────────

  readonly ticketCategories = signal<TicketCategory[]>([]);
  readonly categoryPage = signal(1);
  readonly categoryLastPage = signal(1);
  readonly categoriesLoading = signal(false);
  readonly categoriesError = signal<TicketErrorInfo | null>(null);

  // ─── Ticket create / edit form ────────────────────────────────────────────

  readonly isTicketFormOpen = signal(false);
  readonly editingTicket = signal<Ticket | null>(null);
  readonly isTicketSubmitting = signal(false);
  readonly ticketErrors = signal<Record<string, string>>({});

  ticketForm = new FormGroup({
    title: new FormControl('', [Validators.required, Validators.maxLength(255)]),
    description: new FormControl('', Validators.required),
    category_id: new FormControl<number | null>(null),
    priority: new FormControl<TicketPriority>('MEDIUM'),
  });

  // ─── Category create / edit form ─────────────────────────────────────────

  readonly isCategoryFormOpen = signal(false);
  readonly editingCategory = signal<TicketCategory | null>(null);
  readonly isCategorySubmitting = signal(false);
  readonly categoryErrors = signal<Record<string, string>>({});

  categoryForm = new FormGroup({
    name: new FormControl('', Validators.required),
    code: new FormControl('', Validators.required),
    description: new FormControl<string | null>(null),
  });

  // ─── Confirm dialog ───────────────────────────────────────────────────────

  readonly confirmRequest = signal<ConfirmRequest | null>(null);

  private searchTimer: ReturnType<typeof setTimeout> | null = null;
  private loadSeq = 0;

  trackByTicketId = (_index: number, ticket: Ticket): number => ticket.id;
  trackById = (_index: number, record: { id: number }): number => record.id;

  // ─── Lifecycle ────────────────────────────────────────────────────────────

  ngOnInit(): void {
    this.loadTickets(true);
    this.loadCategoryLookup();

    if (this.isAgent()) {
      this.loadAssignees();
    }

    this.route.queryParams.subscribe(params => {
      const raw = params['selected'];
      if (raw === undefined || raw === null) return;
      const id = Number(raw);
      if (!Number.isInteger(id) || id <= 0) return;
      if (this.selectedTicketId() === id) return;
      this.selectTicket(id);
    });
  }

  ngOnDestroy(): void {
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
    }
  }

  // ─── View switching ───────────────────────────────────────────────────────

  setTab(tab: 'requests' | 'categories'): void {
    this.activeTab.set(tab);
    if (tab === 'categories' && this.ticketCategories().length === 0) {
      this.loadTicketCategories(true);
    }
  }

  // ─── Data loading ─────────────────────────────────────────────────────────

  loadTickets(refresh = false): void {
    if (refresh) {
      this.ticketsLoading.set(true);
    }
    const seq = ++this.loadSeq;

    const filters: TicketListFilters = {
      page: this.currentPage(),
      per_page: this.perPage,
      ...this.sortParams(),
    };
    const search = this.searchQuery().trim();
    if (search) filters.search = search;
    if (this.statusFilter()) filters.status = this.statusFilter() as TicketStatus;
    if (this.priorityFilter()) filters.priority = this.priorityFilter() as TicketPriority;
    if (this.categoryFilter()) filters.category_id = this.categoryFilter();
    if (this.assigneeFilter()) filters.assigned_to = this.assigneeFilter();

    this.ticketService.listTickets(filters).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;
        this.ticketsLoading.set(false);
        if (res.success) {
          this.tickets.set(res.data.items);
          this.ticketsTotal.set(res.data.pagination.total);
          this.currentPage.set(res.data.pagination.current_page);
          this.lastPage.set(res.data.pagination.last_page);
          this.ticketsError.set(null);
        }
      },
      error: (err) => {
        if (seq !== this.loadSeq) return;
        this.ticketsLoading.set(false);
        const info = mapTicketError(err);
        this.ticketsError.set(info);
        if (this.tickets().length === 0) {
          this.toast.danger('Unable to load requests', info.message);
        }
      },
    });
  }

  refreshTickets(): void {
    this.loadTickets(true);
  }

  loadCategoryLookup(): void {
    this.ticketService.listCategories({ page: 1, per_page: 100, sort: 'name', direction: 'asc' }).subscribe({
      next: (res) => {
        if (res.success) {
          this.categories.set(res.data.items);
          this.categoriesTotal.set(res.data.pagination.total);
        }
      },
      error: () => { /* non-fatal: filters simply offer fewer options */ },
    });
  }

  loadAssignees(): void {
    if (this.assignees().length > 0) return;
    this.assetService.listUsers({ is_active: true, per_page: 100 }).subscribe({
      next: (res) => {
        if (res.success) {
          this.assignees.set(res.data.items);
        }
      },
      error: () => { /* non-fatal */ },
    });
  }

  loadTicketCategories(refresh = false): void {
    if (refresh) {
      this.categoriesLoading.set(true);
    }
    this.ticketService.listCategories({ page: this.categoryPage(), per_page: this.perPage, sort: 'name', direction: 'asc' }).subscribe({
      next: (res) => {
        this.categoriesLoading.set(false);
        if (res.success) {
          this.ticketCategories.set(res.data.items);
          this.categoriesTotal.set(res.data.pagination.total);
          this.categoryPage.set(res.data.pagination.current_page);
          this.categoryLastPage.set(res.data.pagination.last_page);
          this.categoriesError.set(null);
        }
      },
      error: (err) => {
        this.categoriesLoading.set(false);
        this.categoriesError.set(mapTicketError(err));
      },
    });
  }

  // ─── Search & filters ─────────────────────────────────────────────────────

  onSearch(value: string): void {
    this.searchQuery.set(value);
    this.currentPage.set(1);
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
    }
    this.searchTimer = setTimeout(() => this.loadTickets(true), SEARCH_DEBOUNCE_MS);
  }

  setStatusFilter(status: TicketStatus | ''): void {
    this.statusFilter.set(status);
    this.currentPage.set(1);
    this.loadTickets(true);
  }

  setPriorityFilter(priority: TicketPriority | ''): void {
    this.priorityFilter.set(priority);
    this.currentPage.set(1);
    this.loadTickets(true);
  }

  setCategoryFilter(id: number | ''): void {
    this.categoryFilter.set(id);
    this.currentPage.set(1);
    this.loadTickets(true);
  }

  setAssigneeFilter(id: number | ''): void {
    this.assigneeFilter.set(id);
    this.currentPage.set(1);
    this.loadTickets(true);
  }

  setSortOption(value: string): void {
    this.sortOption.set(value);
    this.currentPage.set(1);
    this.loadTickets(true);
  }

  clearFilters(): void {
    this.searchQuery.set('');
    this.statusFilter.set('');
    this.priorityFilter.set('');
    this.categoryFilter.set('');
    this.assigneeFilter.set('');
    this.currentPage.set(1);
    this.loadTickets(true);
  }

  goToPage(page: number): void {
    const last = this.lastPage();
    const clamped = Math.max(1, Math.min(page, last));
    if (clamped === this.currentPage()) return;
    this.currentPage.set(clamped);
    this.loadTickets(true);
  }

  goToCategoryPage(page: number): void {
    const last = this.categoryLastPage();
    const clamped = Math.max(1, Math.min(page, last));
    if (clamped === this.categoryPage()) return;
    this.categoryPage.set(clamped);
    this.loadTicketCategories(true);
  }

  // ─── Selection & detail ───────────────────────────────────────────────────

  selectTicket(id: number): void {
    this.selectedTicketId.set(id);
    this.router.navigate([], {
      queryParams: { selected: id },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    this.loadDetail(id);
    this.loadComments(id);
    this.loadHistory(id);
  }

  clearSelection(): void {
    this.selectedTicketId.set(null);
    this.detailTicket.set(null);
    this.detailError.set(null);
    this.comments.set([]);
    this.history.set([]);
    this.router.navigate([], {
      queryParams: { selected: null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  retryDetail(): void {
    const id = this.selectedTicketId();
    if (id !== null) {
      this.loadDetail(id);
      this.loadComments(id);
      this.loadHistory(id);
    }
  }

  loadDetail(id: number): void {
    this.detailTicket.set(null);
    this.detailLoading.set(true);
    this.detailError.set(null);

    this.ticketService.getTicket(id).subscribe({
      next: (res) => {
        this.detailLoading.set(false);
        if (!res.success || !res.data) return;
        this.detailTicket.set(res.data);
      },
      error: (err) => {
        this.detailLoading.set(false);
        this.detailError.set(mapTicketError(err));
      },
    });
  }

  loadComments(id: number): void {
    this.comments.set([]);
    this.commentsLoading.set(true);
    this.commentsError.set(null);

    this.ticketService.listComments(id).subscribe({
      next: (res) => {
        this.commentsLoading.set(false);
        if (res.success) {
          this.comments.set(res.data.items);
        }
      },
      error: (err) => {
        this.commentsLoading.set(false);
        this.commentsError.set(mapTicketError(err));
      },
    });
  }

  loadHistory(id: number): void {
    this.history.set([]);
    this.historyLoading.set(true);
    this.historyError.set(null);

    this.ticketService.listHistory(id).subscribe({
      next: (res) => {
        this.historyLoading.set(false);
        if (res.success) {
          this.history.set(res.data.items);
        }
      },
      error: (err) => {
        this.historyLoading.set(false);
        this.historyError.set(mapTicketError(err));
      },
    });
  }

  private refreshAfterTicketChange(id: number): void {
    this.loadDetail(id);
    this.loadHistory(id);
    this.refreshTickets();
  }

  // ─── Presentation helpers ─────────────────────────────────────────────────

  statusBadge(status: TicketStatus): BadgeStatus {
    return STATUS_BADGE[status] ?? 'neutral';
  }

  priorityBadge(priority: TicketPriority): BadgeStatus {
    return PRIORITY_BADGE[priority] ?? 'neutral';
  }

  nextStatus(ticket: Ticket): TicketStatus {
    return STATUS_TRANSITIONS[ticket.status];
  }

  statusActionLabel(status: TicketStatus): string {
    return STATUS_ACTION_LABELS[status];
  }

  statusLabel(status: TicketStatus | null): string {
    return status ? STATUS_LABELS[status] : '—';
  }

  userName(user: Ticket['requester']): string {
    return user?.name ?? 'Unknown user';
  }

  // ─── Command palette ──────────────────────────────────────────────────────

  openPalette(): void {
    this.palette.open();
  }

  // ─── Ticket create / edit form ────────────────────────────────────────────

  openCreateTicketForm(): void {
    this.editingTicket.set(null);
    this.ticketForm.reset({
      title: '',
      description: '',
      category_id: null,
      priority: 'MEDIUM',
    });
    this.ticketErrors.set({});
    this.isTicketFormOpen.set(true);
  }

  openEditTicketForm(ticket: Ticket): void {
    this.editingTicket.set(ticket);
    this.ticketForm.reset({
      title: ticket.title,
      description: ticket.description ?? '',
      category_id: ticket.category?.id ?? null,
      priority: ticket.priority,
    });
    this.ticketErrors.set({});
    this.isTicketFormOpen.set(true);
  }

  closeTicketForm(): void {
    this.isTicketFormOpen.set(false);
    this.editingTicket.set(null);
    this.ticketErrors.set({});
  }

  clearTicketError(field: string): void {
    this.ticketErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
  }

  submitTicketForm(): void {
    if (this.isTicketSubmitting()) return;

    const errors: Record<string, string> = {};
    const title = String(this.ticketForm.value.title ?? '').trim();
    if (!title) errors['title'] = 'Title is required.';
    else if (title.length > 255) errors['title'] = 'Title may not be longer than 255 characters.';
    if (!this.ticketForm.value.description?.trim()) errors['description'] = 'Description is required.';
    if (Object.keys(errors).length > 0) {
      this.ticketErrors.set(errors);
      this.ticketForm.markAllAsTouched();
      return;
    }

    const payload = this.buildTicketPayload();
    this.isTicketSubmitting.set(true);
    this.ticketErrors.set({});

    const obs = this.editingTicket()
      ? this.ticketService.updateTicket(this.editingTicket()!.id, payload)
      : this.ticketService.createTicket(payload);

    obs.subscribe({
      next: (res) => {
        this.isTicketSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Request submission failed', res.message);
          return;
        }
        const wasEditing = this.editingTicket() !== null;
        this.toast.success(wasEditing ? 'Request updated' : 'Request created', res.data.ticket_number);
        this.closeTicketForm();
        this.refreshTickets();
        if (wasEditing) {
          this.loadDetail(res.data.id);
          this.loadHistory(res.data.id);
        } else {
          this.selectTicket(res.data.id);
        }
      },
      error: (err) => {
        this.isTicketSubmitting.set(false);
        const info = mapTicketError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.ticketErrors.set(info.fieldErrors);
        } else {
          this.toast.danger('Request submission failed', info.message);
        }
      },
    });
  }

  private buildTicketPayload(): TicketPayload {
    const value = this.ticketForm.value;
    return {
      title: String(value.title ?? '').trim(),
      description: String(value.description ?? '').trim(),
      category_id: value.category_id ? Number(value.category_id) : null,
      priority: (value.priority ?? 'MEDIUM') as TicketPriority,
    };
  }

  // ─── Status workflow ──────────────────────────────────────────────────────

  requestStatusChange(ticket: Ticket): void {
    const next = this.nextStatus(ticket);
    const label = STATUS_ACTION_LABELS[ticket.status];
    this.confirmRequest.set({
      title: `${label} request?`,
      message: `Move "${ticket.title}" to ${STATUS_LABELS[next]}? The change will be recorded in the request history.`,
      confirmLabel: label,
      confirmVariant: next === 'OPEN' ? 'secondary' : 'primary',
      action: () => this.runStatusChange(ticket.id, next),
    });
  }

  private runStatusChange(id: number, next: TicketStatus): void {
    this.ticketService.updateTicket(id, { status: next }).subscribe({
      next: (res) => {
        if (!res.success) {
          this.toast.danger('Status update failed', res.message);
          return;
        }
        this.toast.success('Request updated', `${res.data.ticket_number} → ${STATUS_LABELS[next]}`);
        this.refreshAfterTicketChange(id);
      },
      error: (err) => {
        const info = mapTicketError(err);
        if (info.invalidTransition) {
          this.toast.warning('Status unchanged', info.message);
          this.loadDetail(id);
          this.loadHistory(id);
        } else {
          this.toast.danger('Status update failed', info.message);
        }
      },
    });
  }

  // ─── Assignment ───────────────────────────────────────────────────────────

  openAssignDialog(ticket: Ticket): void {
    this.loadAssignees();
    this.assignTarget.set(ticket);
    this.assigneeForm.reset({ user_id: ticket.assignee?.id ?? null });
    this.assignErrors.set({});
    this.assignMessage.set('');
    this.isAssignFormOpen.set(true);
  }

  closeAssignDialog(): void {
    this.isAssignFormOpen.set(false);
    this.assignTarget.set(null);
    this.assignErrors.set({});
    this.assignMessage.set('');
  }

  clearAssignError(field: string): void {
    this.assignErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
  }

  submitAssignment(): void {
    if (this.isAssignSubmitting()) return;
    const target = this.assignTarget();
    if (!target) return;

    const errors: Record<string, string> = {};
    const userId = this.assigneeForm.value.user_id;
    if (!userId) errors['user_id'] = 'Select an assignee.';
    if (Object.keys(errors).length > 0) {
      this.assignErrors.set(errors);
      this.assigneeForm.markAllAsTouched();
      return;
    }

    this.isAssignSubmitting.set(true);
    this.assignErrors.set({});
    this.assignMessage.set('');

    this.ticketService.updateTicket(target.id, { assigned_to: Number(userId) }).subscribe({
      next: (res) => {
        this.isAssignSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Assignment failed', res.message);
          return;
        }
        this.toast.success('Assignment updated', `Request assigned to ${res.data.assignee?.name ?? 'someone'}`);
        this.closeAssignDialog();
        this.refreshAfterTicketChange(target.id);
      },
      error: (err) => {
        this.isAssignSubmitting.set(false);
        const info = mapTicketError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.assignErrors.set(info.fieldErrors);
        } else {
          this.assignMessage.set(info.message);
        }
      },
    });
  }

  requestUnassignTicket(ticket: Ticket): void {
    this.confirmRequest.set({
      title: 'Remove assignment?',
      message: `Ungroup "${ticket.title}" from ${ticket.assignee?.name ?? 'the current assignee'}?`,
      confirmLabel: 'Remove',
      confirmVariant: 'danger',
      action: () => this.runAssignment(ticket.id, null),
    });
  }

  private runAssignment(id: number, assignedTo: number | null): void {
    this.ticketService.updateTicket(id, { assigned_to: assignedTo }).subscribe({
      next: (res) => {
        if (!res.success) {
          this.toast.danger('Assignment update failed', res.message);
          return;
        }
        this.toast.success('Assignment updated', res.data.assignee ? `Assigned to ${res.data.assignee.name}` : 'Unassigned');
        this.refreshAfterTicketChange(id);
      },
      error: (err) => {
        const info = mapTicketError(err);
        this.toast.danger('Assignment update failed', info.message);
      },
    });
  }

  // ─── Comments ─────────────────────────────────────────────────────────────

  clearCommentError(field: string): void {
    this.commentErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
    this.commentMessage.set('');
  }

  submitComment(): void {
    const ticket = this.selectedTicket();
    if (!ticket || this.isCommentSubmitting()) return;

    const errors: Record<string, string> = {};
    const text = String(this.commentForm.value.comment ?? '').trim();
    if (!text) {
      errors['comment'] = 'Write a comment first.';
    } else if (text.length > COMMENT_MAX_LENGTH) {
      errors['comment'] = `Comments may not exceed ${COMMENT_MAX_LENGTH} characters.`;
    }

    if (Object.keys(errors).length > 0) {
      this.commentErrors.set(errors);
      this.commentForm.markAllAsTouched();
      return;
    }

    const payload: TicketCommentPayload = {
      comment: text,
      is_internal: !!this.commentForm.value.is_internal && this.hasManageTickets(),
    };

    this.isCommentSubmitting.set(true);
    this.commentErrors.set({});
    this.commentMessage.set('');

    this.ticketService.createComment(ticket.id, payload).subscribe({
      next: (res) => {
        this.isCommentSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Comment failed', res.message);
          return;
        }
        const internal = res.data.is_internal;
        this.toast.success(internal ? 'Internal note added' : 'Comment added', internal ? 'Visible to agents only.' : 'Visible to the requester.');
        this.commentForm.reset({ comment: '', is_internal: false });
        this.loadComments(ticket.id);
      },
      error: (err) => {
        this.isCommentSubmitting.set(false);
        const info = mapTicketError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.commentErrors.set(info.fieldErrors);
        } else {
          this.commentMessage.set(info.message);
        }
      },
    });
  }

  // ─── Category create / edit ───────────────────────────────────────────────

  openCreateCategoryForm(): void {
    this.editingCategory.set(null);
    this.categoryForm.reset({ name: '', code: '', description: null });
    this.categoryErrors.set({});
    this.isCategoryFormOpen.set(true);
  }

  openEditCategoryForm(category: TicketCategory): void {
    this.editingCategory.set(category);
    this.categoryForm.reset({
      name: category.name,
      code: category.code,
      description: category.description ?? null,
    });
    this.categoryErrors.set({});
    this.isCategoryFormOpen.set(true);
  }

  closeCategoryForm(): void {
    this.isCategoryFormOpen.set(false);
    this.editingCategory.set(null);
    this.categoryErrors.set({});
  }

  clearCategoryError(field: string): void {
    this.categoryErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
  }

  submitCategoryForm(): void {
    if (this.isCategorySubmitting()) return;

    const errors: Record<string, string> = {};
    if (!this.categoryForm.value.name?.trim()) errors['name'] = 'Name is required.';
    if (!this.categoryForm.value.code?.trim()) errors['code'] = 'Code is required.';
    if (Object.keys(errors).length > 0) {
      this.categoryErrors.set(errors);
      this.categoryForm.markAllAsTouched();
      return;
    }

    const payload: TicketCategoryPayload = {
      name: String(this.categoryForm.value.name).trim(),
      code: String(this.categoryForm.value.code).trim().toUpperCase(),
      description: this.categoryForm.value.description?.trim() ? this.categoryForm.value.description.trim() : null,
    };

    this.isCategorySubmitting.set(true);
    this.categoryErrors.set({});

    const obs = this.editingCategory()
      ? this.ticketService.updateCategory(this.editingCategory()!.id, payload)
      : this.ticketService.createCategory(payload);

    obs.subscribe({
      next: (res) => {
        this.isCategorySubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Category submission failed', res.message);
          return;
        }
        this.toast.success(this.editingCategory() ? 'Category updated' : 'Category created', res.data.name);
        this.closeCategoryForm();
        this.loadCategoryLookup();
        this.loadTicketCategories(true);
        this.refreshTickets();
      },
      error: (err) => {
        this.isCategorySubmitting.set(false);
        const info = mapTicketError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.categoryErrors.set(info.fieldErrors);
        } else {
          this.toast.danger('Category submission failed', info.message);
        }
      },
    });
  }

  requestDeleteCategory(category: TicketCategory): void {
    this.confirmRequest.set({
      title: 'Delete category?',
      message: `Delete "${category.name}"? Categories still referenced by requests cannot be deleted.`,
      confirmLabel: 'Delete',
      confirmVariant: 'danger',
      action: () => this.runDeleteCategory(category),
    });
  }

  private runDeleteCategory(category: TicketCategory): void {
    this.ticketService.deleteCategory(category.id).subscribe({
      next: (res) => {
        if (!res.success) {
          this.toast.danger('Delete failed', res.message);
          return;
        }
        this.toast.success('Category deleted', 'The category has been removed.');
        this.loadCategoryLookup();
        this.loadTicketCategories(true);
        this.refreshTickets();
      },
      error: (err) => {
        const info = mapTicketError(err);
        if (err instanceof HttpErrorResponse && err.status === 409) {
          this.toast.warning('Cannot delete category', info.message);
        } else {
          this.toast.danger('Delete failed', info.message);
        }
      },
    });
  }

  // ─── Confirm dialog ───────────────────────────────────────────────────────

  closeConfirm(): void {
    this.confirmRequest.set(null);
  }

  executeConfirm(): void {
    const request = this.confirmRequest();
    if (!request) return;
    this.confirmRequest.set(null);
    request.action();
  }

  // ─── Internal helpers ─────────────────────────────────────────────────────

  private sortParams(): Pick<TicketListFilters, 'sort' | 'direction'> {
    switch (this.sortOption()) {
      case 'created_asc':
        return { sort: 'created_at', direction: 'asc' };
      case 'updated_desc':
        return { sort: 'updated_at', direction: 'desc' };
      case 'ticket_number_desc':
        return { sort: 'ticket_number', direction: 'desc' };
      default:
        return { sort: 'created_at', direction: 'desc' };
    }
  }
}