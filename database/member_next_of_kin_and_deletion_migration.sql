CREATE TABLE IF NOT EXISTS deleted_members (
  DeletedMemberID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  MemberID VARCHAR(40) NOT NULL,
  NationalID VARCHAR(30) NOT NULL,
  FirstName VARCHAR(100) NOT NULL,
  LastName VARCHAR(100) NOT NULL,
  PrimaryNumber VARCHAR(20) NOT NULL,
  Email VARCHAR(150) NULL,
  Status VARCHAR(30) NOT NULL,
  CreatedAt TIMESTAMP NULL,
  MemberData JSON NOT NULL,
  DeletedBy INT UNSIGNED NULL,
  DeletedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_deleted_member_id (MemberID), KEY idx_deleted_national_id (NationalID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS next_of_kin (
  NextOfKinID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  MemberID VARCHAR(40) NOT NULL,
  FullName VARCHAR(180) NOT NULL,
  NationalID VARCHAR(30) NULL,
  DateOfBirth DATE NULL,
  Relationship VARCHAR(80) NOT NULL,
  Phone VARCHAR(30) NOT NULL,
  Allocation DECIMAL(5,2) NULL,
  CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_next_of_kin_member (MemberID),
  CONSTRAINT fk_next_of_kin_member FOREIGN KEY (MemberID) REFERENCES members(MemberID) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO next_of_kin (MemberID, FullName, NationalID, DateOfBirth, Relationship, Phone, Allocation)
SELECT MemberID, NomineeName, NomineeNationalID, NomineeDateOfBirth, NomineeRelationship, NomineePhone, NomineeAllocation
FROM members WHERE NomineeName IS NOT NULL AND NomineeName <> '';
ALTER TABLE members DROP COLUMN NomineeName, DROP COLUMN NomineeNationalID, DROP COLUMN NomineeDateOfBirth,
  DROP COLUMN NomineeRelationship, DROP COLUMN NomineePhone, DROP COLUMN NomineeAllocation;
