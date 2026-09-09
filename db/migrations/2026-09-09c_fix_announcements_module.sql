DELETE FROM `role_permissions` WHERE `module_id` = 'announcements';
DELETE FROM `modules`          WHERE `id`        = 'announcements';

INSERT INTO `modules` (`id`,`label`,`icon`,`sort_order`,`active`) VALUES
  ('announcements-admin','Σύνταξη ανακοινώσεων','ti-speakerphone',55,1)
ON DUPLICATE KEY UPDATE `label`=VALUES(`label`);

INSERT INTO `role_permissions`
  (`role_id`,`module_id`,`can_view`,`can_add`,`can_edit`,`can_delete`,`can_export`)
VALUES (2,'announcements-admin',0,0,0,0,0),
       (3,'announcements-admin',0,0,0,0,0),
       (4,'announcements-admin',0,0,0,0,0),
       (5,'announcements-admin',0,0,0,0,0)
ON DUPLICATE KEY UPDATE `can_view`=0;

SELECT * FROM `modules` WHERE `id` LIKE 'announcement%';
SELECT * FROM `role_permissions` WHERE `module_id` LIKE 'announcement%';
