CREATE TABLE tt_content (
    tx_hhextfaqs_records text,
    tx_hhextfaqs_categories text,
    tx_hhextfaqs_category_conjunction varchar(8) DEFAULT 'or' NOT NULL,
    tx_hhextfaqs_sort_field varchar(32) DEFAULT 'sorting' NOT NULL,
    tx_hhextfaqs_sort_order varchar(4) DEFAULT 'asc' NOT NULL,
    tx_hhextfaqs_structured_data smallint unsigned DEFAULT '1' NOT NULL,
    tx_hhextfaqs_list_elements text,
    tx_hhextfaqs_auto_expand smallint unsigned DEFAULT '1' NOT NULL
);

CREATE TABLE tx_hhextfaqs_domain_model_faq (
    question varchar(255) DEFAULT '' NOT NULL,
    answer text,
    media int(11) unsigned DEFAULT '0' NOT NULL,
    categories int(11) unsigned DEFAULT '0' NOT NULL
);
