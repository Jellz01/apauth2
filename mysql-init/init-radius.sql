-- ================================
-- Minimal FreeRADIUS Schema for MAC Authentication
-- ================================

USE radius;

-- ================================
-- 1. YOUR CLIENTS TABLE (Main table for web interface)
-- ================================
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100),
    apellido VARCHAR(100),
    cedula VARCHAR(20),
    telefono VARCHAR(20),
    email VARCHAR(100),
    mac VARCHAR(20) UNIQUE,
    enabled TINYINT(1) DEFAULT 1
);

-- ================================
-- 2. FreeRADIUS Standard Tables
-- ================================
CREATE TABLE IF NOT EXISTS radacct (
    radacctid bigint(21) NOT NULL auto_increment,
    acctsessionid varchar(64) NOT NULL default '',
    acctuniqueid varchar(32) NOT NULL default '',
    username varchar(64) NOT NULL default '',
    groupname varchar(64) NOT NULL default '',
    realm varchar(64) default '',
    nasipaddress varchar(15) NOT NULL default '',
    nasportid varchar(15) default NULL,
    nasporttype varchar(32) default NULL,
    acctstarttime datetime NULL default NULL,
    acctupdatetime datetime NULL default NULL,
    acctstoptime datetime NULL default NULL,
    acctinterval int(12) default NULL,
    acctsessiontime int(12) unsigned default NULL,
    acctauthentic varchar(32) default NULL,
    connectinfo_start varchar(50) default NULL,
    connectinfo_stop varchar(50) default NULL,
    acctinputoctets bigint(20) default NULL,
    acctoutputoctets bigint(20) default NULL,
    calledstationid varchar(50) NOT NULL default '',
    callingstationid varchar(50) NOT NULL default '',
    acctterminatecause varchar(32) NOT NULL default '',
    servicetype varchar(32) default NULL,
    framedprotocol varchar(32) default NULL,
    framedipaddress varchar(15) NOT NULL default '',
    framedipv6address varchar(45) NOT NULL default '',
    framedipv6prefix varchar(45) NOT NULL default '',
    framedinterfaceid varchar(44) NOT NULL default '',
    delegatedipv6prefix varchar(45) NOT NULL default '',
    class varchar(64) default NULL,
    PRIMARY KEY (radacctid),
    UNIQUE KEY acctuniqueid (acctuniqueid),
    KEY username (username),
    KEY framedipaddress (framedipaddress),
    KEY framedipv6address (framedipv6address),
    KEY framedipv6prefix (framedipv6prefix),
    KEY framedinterfaceid (framedinterfaceid),
    KEY delegatedipv6prefix (delegatedipv6prefix),
    KEY acctsessionid (acctsessionid),
    KEY acctsessiontime (acctsessiontime),
    KEY acctstarttime (acctstarttime),
    KEY acctinterval (acctinterval),
    KEY acctstoptime (acctstoptime),
    KEY nasipaddress (nasipaddress)
);

CREATE TABLE IF NOT EXISTS radcheck (
    id int(11) unsigned NOT NULL auto_increment,
    username varchar(64) NOT NULL default '',
    attribute varchar(64)  NOT NULL default '',
    op char(2) NOT NULL DEFAULT '==',
    value varchar(253) NOT NULL default '',
    PRIMARY KEY  (id),
    KEY username (username(32))
);

CREATE TABLE IF NOT EXISTS radgroupcheck (
    id int(11) unsigned NOT NULL auto_increment,
    groupname varchar(64) NOT NULL default '',
    attribute varchar(64) NOT NULL default '',
    op char(2) NOT NULL DEFAULT '==',
    value varchar(253) NOT NULL default '',
    PRIMARY KEY  (id),
    KEY groupname (groupname(32))
);

CREATE TABLE IF NOT EXISTS radgroupreply (
    id int(11) unsigned NOT NULL auto_increment,
    groupname varchar(64) NOT NULL default '',
    attribute varchar(64) NOT NULL default '',
    op char(2) NOT NULL DEFAULT '=',
    value varchar(253) NOT NULL default '',
    PRIMARY KEY  (id),
    KEY groupname (groupname(32))
);

CREATE TABLE IF NOT EXISTS radreply (
    id int(11) unsigned NOT NULL auto_increment,
    username varchar(64) NOT NULL default '',
    attribute varchar(64) NOT NULL default '',
    op char(2) NOT NULL DEFAULT '=',
    value varchar(253) NOT NULL default '',
    PRIMARY KEY  (id),
    KEY username (username(32))
);

CREATE TABLE IF NOT EXISTS radusergroup (
    id int(11) unsigned NOT NULL auto_increment,
    username varchar(64) NOT NULL default '',
    groupname varchar(64) NOT NULL default '',
    priority int(11) NOT NULL default '1',
    PRIMARY KEY  (id),
    KEY username (username(32))
);

CREATE TABLE IF NOT EXISTS radpostauth (
    id int(11) NOT NULL auto_increment,
    username varchar(64) NOT NULL default '',
    pass varchar(64) NOT NULL default '',
    reply varchar(32) NOT NULL default '',
    authdate timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY username (username(32))
);

-- ================================
-- 3. Sample Data for MAC Authentication
-- ================================

-- Insert MAC addresses with Auth-Type := Accept (no password needed)
INSERT IGNORE INTO radcheck (username, attribute, op, value) VALUES
('AA:BB:CC:DD:EE:FF', 'Auth-Type', ':=', 'Accept'),
('11-22-33-44-55-66', 'Auth-Type', ':=', 'Accept'),
('A1-B2-C3-D4-E5-F6', 'Auth-Type', ':=', 'Accept');

-- Insert corresponding client records
INSERT IGNORE INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled) VALUES
('Juan', 'Pérez', '123456789', '555-1234', 'juan@example.com', 'AA:BB:CC:DD:EE:FF', 1),
('María', 'Gómez', '987654321', '555-5678', 'maria@example.com', '11-22-33-44-55-66', 1),
('Carlos', 'López', '456789123', '555-9012', 'carlos@example.com', 'A1-B2-C3-D4-E5-F6', 1);

-- Optional: Add reply attributes for MAC users
INSERT IGNORE INTO radreply (username, attribute, op, value) VALUES
('AA:BB:CC:DD:EE:FF', 'Session-Timeout', '=', '3600'),
('AA:BB:CC:DD:EE:FF', 'Idle-Timeout', '=', '300'),
('AA:BB:CC:DD:EE:FF', 'Framed-IP-Address', '=', '10.0.0.100');