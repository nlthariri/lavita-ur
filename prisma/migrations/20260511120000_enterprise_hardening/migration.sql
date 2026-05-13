-- Add session versioning for immediate session revocation on role/team changes
ALTER TABLE `users`
  ADD COLUMN `sessionVersion` INTEGER NOT NULL DEFAULT 1;

-- Immutable audit trail for privileged mutations
CREATE TABLE `audit_events` (
  `id` VARCHAR(191) NOT NULL,
  `organizationId` VARCHAR(191) NOT NULL,
  `actorId` VARCHAR(191) NOT NULL,
  `action` VARCHAR(191) NOT NULL,
  `targetType` VARCHAR(191) NOT NULL,
  `targetId` VARCHAR(191) NOT NULL,
  `beforeData` JSON NULL,
  `afterData` JSON NULL,
  `requestId` VARCHAR(191) NULL,
  `ipAddress` VARCHAR(191) NULL,
  `userAgent` VARCHAR(191) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `audit_events_organizationId_createdAt_idx`(`organizationId`, `createdAt`),
  INDEX `audit_events_actorId_createdAt_idx`(`actorId`, `createdAt`),
  INDEX `audit_events_targetType_targetId_createdAt_idx`(`targetType`, `targetId`, `createdAt`),
  CONSTRAINT `audit_events_organizationId_fkey` FOREIGN KEY (`organizationId`) REFERENCES `organizations`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `audit_events_actorId_fkey` FOREIGN KEY (`actorId`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Immutable work entry history for forensic traceability
CREATE TABLE `work_entry_history` (
  `id` VARCHAR(191) NOT NULL,
  `organizationId` VARCHAR(191) NOT NULL,
  `workEntryId` VARCHAR(191) NOT NULL,
  `version` INTEGER NOT NULL,
  `changedById` VARCHAR(191) NOT NULL,
  `changeReason` VARCHAR(191) NOT NULL,
  `startAt` DATETIME(3) NOT NULL,
  `endAt` DATETIME(3) NOT NULL,
  `pauseMinutes` INTEGER NOT NULL,
  `netMinutes` INTEGER NOT NULL,
  `note` TEXT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE INDEX `work_entry_history_workEntryId_version_key`(`workEntryId`, `version`),
  INDEX `work_entry_history_organizationId_createdAt_idx`(`organizationId`, `createdAt`),
  INDEX `work_entry_history_changedById_createdAt_idx`(`changedById`, `createdAt`),
  CONSTRAINT `work_entry_history_organizationId_fkey` FOREIGN KEY (`organizationId`) REFERENCES `organizations`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `work_entry_history_workEntryId_fkey` FOREIGN KEY (`workEntryId`) REFERENCES `work_entries`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `work_entry_history_changedById_fkey` FOREIGN KEY (`changedById`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Transactional outbox for asynchronous side effects with idempotency
CREATE TABLE `outbox_events` (
  `id` VARCHAR(191) NOT NULL,
  `organizationId` VARCHAR(191) NOT NULL,
  `aggregateType` VARCHAR(191) NOT NULL,
  `aggregateId` VARCHAR(191) NOT NULL,
  `eventType` VARCHAR(191) NOT NULL,
  `idempotencyKey` VARCHAR(191) NOT NULL,
  `payload` JSON NOT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'queued',
  `retryCount` INTEGER NOT NULL DEFAULT 0,
  `nextAttemptAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `sentAt` DATETIME(3) NULL,
  `failedAt` DATETIME(3) NULL,
  `errorMessage` TEXT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `outbox_events_idempotencyKey_key`(`idempotencyKey`),
  INDEX `outbox_events_status_nextAttemptAt_idx`(`status`, `nextAttemptAt`),
  INDEX `outbox_events_organizationId_eventType_createdAt_idx`(`organizationId`, `eventType`, `createdAt`),
  CONSTRAINT `outbox_events_organizationId_fkey` FOREIGN KEY (`organizationId`) REFERENCES `organizations`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Durable operational job telemetry for retention and reporting tasks
CREATE TABLE `system_job_runs` (
  `id` VARCHAR(191) NOT NULL,
  `organizationId` VARCHAR(191) NULL,
  `jobName` VARCHAR(191) NOT NULL,
  `status` VARCHAR(191) NOT NULL,
  `startedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `finishedAt` DATETIME(3) NULL,
  `durationMs` INTEGER NULL,
  `rowsAffected` INTEGER NOT NULL DEFAULT 0,
  `details` JSON NULL,
  `errorMessage` TEXT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  INDEX `system_job_runs_jobName_createdAt_idx`(`jobName`, `createdAt`),
  INDEX `system_job_runs_status_createdAt_idx`(`status`, `createdAt`),
  CONSTRAINT `system_job_runs_organizationId_fkey` FOREIGN KEY (`organizationId`) REFERENCES `organizations`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
