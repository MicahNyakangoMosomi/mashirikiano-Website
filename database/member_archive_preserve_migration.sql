-- Allows a member row to remain in place while it is hidden from active member lists.
ALTER TABLE members
  MODIFY Status ENUM('Active','Suspended','Pending','Deleted') NOT NULL DEFAULT 'Pending';
