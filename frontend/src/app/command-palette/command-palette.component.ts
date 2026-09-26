import { Component, inject, HostListener, ElementRef, ViewChild, effect } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { IonIcon } from '@ionic/angular';
import { CommandPaletteService } from '../core/services/command-palette.service';

@Component({
  selector: 'app-command-palette',
  standalone: true,
  imports: [CommonModule, FormsModule, IonIcon],
  templateUrl: './command-palette.component.html',
  styleUrl: './command-palette.component.scss',
})
export class CommandPaletteComponent {
  @ViewChild('searchInput') searchInput!: ElementRef<HTMLInputElement>;

  private readonly palette = inject(CommandPaletteService);

  readonly isOpen = this.palette.isOpen;
  readonly query = this.palette.query;
  readonly activeIndex = this.palette.activeIndex;
  readonly filteredCommands = this.palette.filteredCommands;

  readonly groups = [
    { id: 'Navigation', icon: 'location' },
    { id: 'Assets', icon: 'cube-outline' },
    { id: 'Requests', icon: 'document-outline' },
    { id: 'System', icon: 'settings-outline' },
  ];

  constructor() {
    effect(() => {
      if (this.isOpen()) {
        setTimeout(() => this.searchInput?.nativeElement.focus(), 50);
      }
    });
  }

  @HostListener('document:keydown', ['$event'])
  onKeyDown(event: KeyboardEvent): void {
    if (!this.isOpen()) return;

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        this.palette.moveDown();
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.palette.moveUp();
        break;
      case 'Enter':
        event.preventDefault();
        this.palette.executeActive();
        break;
      case 'Escape':
        event.preventDefault();
        this.palette.close();
        break;
    }
  }

  onQueryInput(value: string): void {
    this.palette.setQuery(value);
  }

  onCommandClick(command: any): void {
    command.action();
    this.palette.close();
  }

  onItemHover(index: number): void {
    this.palette.setActive(index);
  }

  hasCommandsInGroup(groupId: string): boolean {
    return this.filteredCommands().some(c => c.group === groupId);
  }

  getIconForGroup(groupId: string): string {
    const group = this.groups.find(g => g.id === groupId);
    return group?.icon ?? 'ellipsis-vertical';
  }
}
