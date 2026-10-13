CREATE TABLE tx_semanticdelivery_channel_content (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    channel_identifier varchar(100) DEFAULT '' NOT NULL,
    page_uid int(11) DEFAULT '0' NOT NULL,
    content text,
    metadata text,
    status varchar(20) DEFAULT 'draft' NOT NULL,
    output_format varchar(20) DEFAULT 'html' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY idx_channel_page (channel_identifier, page_uid),
    KEY idx_status (status)
);

CREATE TABLE tx_semanticdelivery_distribution_job (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    channel_content_uid int(11) DEFAULT '0' NOT NULL,
    distributor varchar(100) DEFAULT '' NOT NULL,
    platform varchar(100) DEFAULT '' NOT NULL,
    status varchar(20) DEFAULT 'pending' NOT NULL,
    external_id varchar(255) DEFAULT '' NOT NULL,
    external_url varchar(500) DEFAULT '' NOT NULL,
    error_message text,
    scheduled_at int(11) unsigned DEFAULT '0' NOT NULL,
    executed_at int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY idx_status (status),
    KEY idx_channel_content (channel_content_uid),
    KEY idx_scheduled (scheduled_at)
);

CREATE TABLE tx_semanticdelivery_oauth_token (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    platform varchar(50) DEFAULT '' NOT NULL,
    site_identifier varchar(100) DEFAULT '' NOT NULL,
    access_token text,
    refresh_token text,
    key_version tinyint(3) unsigned DEFAULT '1' NOT NULL,
    token_type varchar(50) DEFAULT 'bearer' NOT NULL,
    expires_at int(11) unsigned DEFAULT '0' NOT NULL,
    scope varchar(500) DEFAULT '' NOT NULL,
    account_id varchar(255) DEFAULT '' NOT NULL,
    account_name varchar(255) DEFAULT '' NOT NULL,
    metadata text,

    PRIMARY KEY (uid),
    UNIQUE KEY idx_platform_site (platform, site_identifier),
    KEY idx_expires (expires_at)
);

CREATE TABLE pages (
    tx_semanticdelivery_channels varchar(255) DEFAULT '' NOT NULL,
    tx_semanticdelivery_distribution_enabled tinyint(1) unsigned DEFAULT '0' NOT NULL,
    tx_semanticdelivery_distribution_platforms varchar(255) DEFAULT '' NOT NULL
);
