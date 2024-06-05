INSERT INTO Tools(image_tool, name_tool, api_name_tool)
VALUES ('my_english_buddy.jpg', 'Outils de langues', 'buddies'),
       ('th_itexpertai.jpg', 'IT Expert IA', 'it-expert'),
       ('th_ittrainingia.jpg', 'IT Training IA', 'it-training'),
       ('th_recrutia.jpg', 'Recrut\'IA', 'recrutia'),
       ('th_ia_vente.jpg', 'Outils de vente', 'sales'),
       ('th_samsia.jpg', 'SAMS\'IA', 'samsia'),
       ('th_orientia.jpg', 'Orient\'IA', 'secretaries');

INSERT INTO Languages(short_name_language, name_language, image_language, is_active, id_tool)
VALUES ('en', 'My English buddy', 'my_english_buddy.jpg', FALSE, 1),
       ('es', 'Mi amiga española', 'mi_amiga_espanola.jpg', FALSE, 1),
       ('de', 'Mein deutscher kumpel', 'mein_deutscher_kumpel.jpg', FALSE, 1),
       ('fr', 'Mon amie française', 'mon_amie_francaise.jpg', FALSE, 1),
       ('it', 'Il mio amico italiano', 'il_mio_amico_italiano.jpg', FALSE, 1),
       ('po', 'Minha amiga Portuguesa', 'minha_amiga_portuguesa.jpg', FALSE, 1),
       ('fr', 'IT Expert IA', 'th_itexpertai.jpg', FALSE, 2),
       ('fr', 'IT Training IA', 'th_ittrainingia.jpg', FALSE, 3),
       ('fr', 'Recrut\'IA', 'th_recrutia.jpg', FALSE, 4),
       ('en', 'SALES\'IA', 'th_ia_vente.jpg', FALSE, 5),
       ('fr', 'Vente IA', 'th_ia_vente.jpg', FALSE, 5),
       ('fr', 'SAMS\'IA', 'th_samsia.jpg', FALSE, 6),
       ('fr', 'Orient\'IA', 'th_orientia.jpg', FALSE, 7);

INSERT INTO Levels(name_level, level)
VALUES ('Débutant', 30),
       ('Intermédiaire', 60),
       ('Expert', 90),
       ('Défaut', 100),
       ('Défaut', 100),
       ('Défaut', 100),
       ('Défaut', 100),
       ('Défaut', 100),
       ('Défaut', 100);

INSERT INTO Parameters(id_params, full_name_params, content_params)
VALUES (1, 'Emplacement dans la réponse des messages', 'choices'),
       (2, 'Emplacement dans les paramètres des messages', 'messages'),
       (3, 'Emplacement dans la réponse du model utilisé', 'model'),
       (4, 'Emplacement dans la réponse des jetons de complétion', 'usage.completion_tokens'),
       (5, 'Emplacement dans la réponse des jetons du prompt initial', 'usage.prompt_tokens'),
       (6, 'Emplacement dans la réponse des jetons utilisés', 'usage.total_tokens');

INSERT INTO CRON(expression_CRON, commande_CRON)
VALUES ('0 * * * *', 'CRON_COMMANDE_GET_LOG'),
       ('0 * * * *', 'CRON_COMMANDE_GET_THREAD'),
       ('0 * * * *', 'CRON_COMMANDE_GET_BACKUP_ASSISTANTS');

INSERT INTO Errors_Types(id_error_type, name_error_type)
VALUES (1, 'Erreur lors de la requête à l\'API d\'OnlineFormaPro'),
       (2, 'Erreur dans la réponse de l\'API d\'OnlineFormaPro : La réponse est vide'),
       (3, 'Erreur dans la réponse de l\'API d\'OnlineFormaPro : Le format de la réponse est invalide'),
       (4, 'Erreur lors de la requête à l\'API d\'OpenAI'),
       (5, 'Erreur dans la réponse de l\'API d\'OpenAI'),
       (6, 'Erreur lors de l\'exécution du script'),
       (7, 'Erreur dans la réponse de l\'API d\'OpenAI : La réponse est incomplète'),
       (8, 'Erreur dans la réponse de l\'API d\'OpenAI : Le run a échoué'),
       (9, 'Erreur lors de la requête à l\'API d\'OpenAI : Impossible de récupérer les messages');

INSERT INTO levels_tools(id_tool, id_level)
VALUES (1, 1),
       (1, 2),
       (1, 3),
       (2, 4),
       (3, 5),
       (4, 6),
       (5, 7),
       (6, 8),
       (7, 9);