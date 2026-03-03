-- INSERT stock général au 31.12.2025 (site_id = NULL)
-- Exécuter après insert-modele-stock.sql (modèles, sites, pièces)
-- Prérequis: migration Version20260223270000 (site_id nullable)

-- ========== PIÈCES (à insérer si pas déjà présentes) ==========
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-IMC5500-N', 'Toner noir RICOH IM C5500', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-IMC5500-C', 'Toner cyan RICOH IM C5500', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-IMC5500-M', 'Toner magenta RICOH IM C5500', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-IMC5500-J', 'Toner jaune RICOH IM C5500', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('BC-IMC5500', 'Bac récupération RICOH IM C5500', 'bac_recup', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-IMC3000-N', 'Toner noir RICOH IM C3000', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-IMC3000-C', 'Toner cyan RICOH IM C3000', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-IMC3000-M', 'Toner magenta RICOH IM C3000', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-IMC3000-J', 'Toner jaune RICOH IM C3000', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('BC-IMC3000', 'Bac récupération RICOH IM C3000', 'bac_recup', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC5504-N', 'Toner noir RICOH MP C5504', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC5504-C', 'Toner cyan RICOH MP C5504', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC5504-M', 'Toner magenta RICOH MP C5504', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC5504-J', 'Toner jaune RICOH MP C5504', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('BC-MPC5504', 'Bac récupération RICOH MP C5504', 'bac_recup', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC3004-N', 'Toner noir RICOH MP C3004', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC3004-C', 'Toner cyan RICOH MP C3004', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC3004-M', 'Toner magenta RICOH MP C3004', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC3004-J', 'Toner jaune RICOH MP C3004', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('BC-MPC3004', 'Bac récupération RICOH MP C3004', 'bac_recup', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC2004-N', 'Toner noir RICOH MP C2004', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('BC-MPC2004', 'Bac récupération RICOH MP C2004', 'bac_recup', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC2504-N', 'Toner noir RICOH MP C2504', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC2504-C', 'Toner cyan RICOH MP C2504', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC2504-M', 'Toner magenta RICOH MP C2504', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-MPC2504-J', 'Toner jaune RICOH MP C2504', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('BC-MPC2504', 'Bac récupération RICOH MP C2504', 'bac_recup', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-IMC4500-N', 'Toner noir RICOH IM C4500', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-IMC3500-N', 'Toner noir RICOH IM C3500', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('TNR-IMC2500-N', 'Toner noir RICOH IM C2500', 'toner', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('DRUM-MPC5504', 'Tambour RICOH MP C5504', 'drum', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('DRUM-MPC3004', 'Tambour RICOH MP C3004', 'drum', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('KIT-IMC5500', 'Kit entretien RICOH IM C5500', 'kit_entretien', NOW());
INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES ('KIT-MPC5504', 'Kit entretien RICOH MP C5504', 'kit_entretien', NOW());

-- ========== STOCK GÉNÉRAL 31.12.2025 (site_id = NULL) ==========
-- ON DUPLICATE KEY UPDATE: met à jour si la ligne (piece_id, NULL) existe déjà
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 12, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-IMC5500-N'
ON DUPLICATE KEY UPDATE quantite = 12, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 8, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-IMC5500-C'
ON DUPLICATE KEY UPDATE quantite = 8, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 8, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-IMC5500-M'
ON DUPLICATE KEY UPDATE quantite = 8, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 8, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-IMC5500-J'
ON DUPLICATE KEY UPDATE quantite = 8, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 6, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'BC-IMC5500'
ON DUPLICATE KEY UPDATE quantite = 6, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 15, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-IMC3000-N'
ON DUPLICATE KEY UPDATE quantite = 15, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 10, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-IMC3000-C'
ON DUPLICATE KEY UPDATE quantite = 10, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 10, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-IMC3000-M'
ON DUPLICATE KEY UPDATE quantite = 10, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 10, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-IMC3000-J'
ON DUPLICATE KEY UPDATE quantite = 10, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 8, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'BC-IMC3000'
ON DUPLICATE KEY UPDATE quantite = 8, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 14, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC5504-N'
ON DUPLICATE KEY UPDATE quantite = 14, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 9, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC5504-C'
ON DUPLICATE KEY UPDATE quantite = 9, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 9, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC5504-M'
ON DUPLICATE KEY UPDATE quantite = 9, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 9, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC5504-J'
ON DUPLICATE KEY UPDATE quantite = 9, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 7, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'BC-MPC5504'
ON DUPLICATE KEY UPDATE quantite = 7, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 18, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC3004-N'
ON DUPLICATE KEY UPDATE quantite = 18, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 12, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC3004-C'
ON DUPLICATE KEY UPDATE quantite = 12, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 12, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC3004-M'
ON DUPLICATE KEY UPDATE quantite = 12, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 12, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC3004-J'
ON DUPLICATE KEY UPDATE quantite = 12, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 10, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'BC-MPC3004'
ON DUPLICATE KEY UPDATE quantite = 10, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 25, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC2004-N'
ON DUPLICATE KEY UPDATE quantite = 25, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 12, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'BC-MPC2004'
ON DUPLICATE KEY UPDATE quantite = 12, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 16, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC2504-N'
ON DUPLICATE KEY UPDATE quantite = 16, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 11, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC2504-C'
ON DUPLICATE KEY UPDATE quantite = 11, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 11, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC2504-M'
ON DUPLICATE KEY UPDATE quantite = 11, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 11, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-MPC2504-J'
ON DUPLICATE KEY UPDATE quantite = 11, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 9, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'BC-MPC2504'
ON DUPLICATE KEY UPDATE quantite = 9, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 10, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-IMC4500-N'
ON DUPLICATE KEY UPDATE quantite = 10, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 8, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-IMC3500-N'
ON DUPLICATE KEY UPDATE quantite = 8, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 6, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'TNR-IMC2500-N'
ON DUPLICATE KEY UPDATE quantite = 6, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 3, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'DRUM-MPC5504'
ON DUPLICATE KEY UPDATE quantite = 3, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 4, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'DRUM-MPC3004'
ON DUPLICATE KEY UPDATE quantite = 4, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 2, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'KIT-IMC5500'
ON DUPLICATE KEY UPDATE quantite = 2, date_reference = '2025-12-31', updated_at = NOW();
INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at)
SELECT p.id, NULL, 2, '2025-12-31', NOW() FROM piece p WHERE p.reference = 'KIT-MPC5504'
ON DUPLICATE KEY UPDATE quantite = 2, date_reference = '2025-12-31', updated_at = NOW();

-- Fin stock général 31.12.2025
