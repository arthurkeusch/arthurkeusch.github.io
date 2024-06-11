CREATE TABLE Tools
(
    id_tool       INT AUTO_INCREMENT,
    name_tool     TEXT,
    api_name_tool TEXT,
    PRIMARY KEY (id_tool)
);

CREATE TABLE Languages
(
    id_language         INT AUTO_INCREMENT,
    short_name_language TEXT,
    name_language       TEXT,
    image_language      TEXT,
    is_active           BOOLEAN,
    id_tool             INT NOT NULL,
    PRIMARY KEY (id_language),
    FOREIGN KEY (id_tool) REFERENCES Tools (id_tool)
);

CREATE TABLE Levels
(
    id_level   INT AUTO_INCREMENT,
    name_level TEXT,
    level      INT,
    PRIMARY KEY (id_level)
);

CREATE TABLE Parameters
(
    id_params        INT,
    full_name_params TEXT,
    content_params   TEXT,
    PRIMARY KEY (id_params)
);

CREATE TABLE CRON
(
    id_CRON         INT AUTO_INCREMENT,
    expression_CRON TEXT,
    commande_CRON   TEXT,
    PRIMARY KEY (id_CRON)
);

CREATE TABLE Errors_Types
(
    id_error_type   INT,
    name_error_type TEXT,
    PRIMARY KEY (id_error_type)
);

CREATE TABLE Logs
(
    id_log        INT AUTO_INCREMENT,
    date_log      DATETIME,
    params_log    TEXT,
    response_log  TEXT,
    isError       BOOLEAN,
    latency_log   INT,
    id_error_type INT,
    id_language   INT NOT NULL,
    id_level      INT NOT NULL,
    PRIMARY KEY (id_log),
    FOREIGN KEY (id_error_type) REFERENCES Errors_Types (id_error_type),
    FOREIGN KEY (id_language) REFERENCES Languages (id_language),
    FOREIGN KEY (id_level) REFERENCES Levels (id_level)
);

CREATE TABLE levels_tools
(
    id_tool  INT,
    id_level INT,
    PRIMARY KEY (id_tool, id_level),
    FOREIGN KEY (id_tool) REFERENCES Tools (id_tool),
    FOREIGN KEY (id_level) REFERENCES Levels (id_level)
);

CREATE TABLE active_level
(
    id_language INT,
    id_level    INT,
    PRIMARY KEY (id_language, id_level),
    FOREIGN KEY (id_language) REFERENCES Languages (id_language),
    FOREIGN KEY (id_level) REFERENCES Levels (id_level)
);

CREATE TABLE Assistants
(
    id_assistant     INT AUTO_INCREMENT,
    name_assistant   TEXT,
    object_assistant TEXT,
    isActive         BOOLEAN,
    PRIMARY KEY (id_assistant)
);

CREATE TABLE Threads
(
    id_thread              INT AUTO_INCREMENT,
    date_thread            DATETIME,
    object_thread          TEXT,
    object_run             TEXT,
    object_list_messages   TEXT,
    latency_thread         INT,
    isError                BOOLEAN,
    instructions_assistant TEXT,
    id_error_type          INT,
    id_assistant           INT NOT NULL,
    PRIMARY KEY (id_thread),
    FOREIGN KEY (id_error_type) REFERENCES Errors_Types (id_error_type),
    FOREIGN KEY (id_assistant) REFERENCES Assistants (id_assistant)
);

CREATE TABLE BackUp
(
    id_backup   INT AUTO_INCREMENT,
    date_backup DATETIME,
    PRIMARY KEY (id_backup)
);

CREATE TABLE BackUp_Assistants
(
    id_backup_assistant     INT AUTO_INCREMENT,
    date_backup_assistant   DATETIME,
    object_backup_assistant TEXT,
    id_backup               INT NOT NULL,
    PRIMARY KEY (id_backup_assistant),
    FOREIGN KEY (id_backup) REFERENCES BackUp (id_backup)
);