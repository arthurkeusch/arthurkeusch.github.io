<?php

namespace App\Controller;

use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class StatisticsController extends DefaultController
{


    /**
     * Affiche une liste de statistiques.
     *
     * @return Response La réponse HTTP avec les données des statistiques.
     */
    #[Route('/statistics', name: 'list_statistics', methods: ['GET'])]
    public function list_statistics(): Response
    {
        return $this->render('Statistics/list_statistics.html.twig', [
            'title' => 'Statistiques'
        ]);
    }


    /**
     * Affiche une liste de statistiques pour les assistants.
     *
     * @return Response La réponse HTTP avec les données des statistiques.
     */
    #[Route('/statistics/assistants', name: 'list_statistics_assistants', methods: ['GET'])]
    public function list_statistics_assistants(): Response
    {
        $data_boxplotGroupByHours_assistants = $this->select("
SELECT date_thread, latency_thread, isError
FROM Threads;
");
        $data_linechart_assistant = $this->select("
SELECT
    DATE_FORMAT(date_thread, '%Y-%m-%d') AS date,
    MAX(latency_thread) AS max_latency,
    MIN(latency_thread) AS min_latency,
    (
        SELECT AVG(latency_thread)
        FROM (
                 SELECT
                     latency_thread,
                     DATE(date_thread) AS thread_date,
                     ROW_NUMBER() OVER (PARTITION BY DATE(date_thread) ORDER BY latency_thread) AS row_num,
                     COUNT(*) OVER (PARTITION BY DATE(date_thread)) AS total_rows
                 FROM Threads
                 WHERE isError = 0
             ) AS sorted_threads
        WHERE row_num IN (FLOOR((total_rows + 1) / 2), CEIL((total_rows + 1) / 2))
          AND thread_date = DATE(outer_threads.date_thread)
    ) AS average_latency
FROM Threads AS outer_threads
WHERE isError = 0
GROUP BY DATE(date_thread)
ORDER BY date_thread;
");
        $data_piechart_assistant = $this->select("

SELECT ET.id_error_type, name_error_type AS name, COUNT(*) AS value
FROM Threads
JOIN Errors_Types ET on ET.id_error_type = Threads.id_error_type
GROUP BY ET.id_error_type;
");
        $data_boxplot_assistants = $this->select("
SELECT id_thread, latency_thread, id_assistant
FROM Threads
WHERE isError = 0;
");
        $assistants_boxplot_assistants = $this->select("
SELECT id_assistant, name_assistant
FROM Assistants;
");
        $data_stackedbarchart_assistants = $this->select("
SELECT id_assistant, id_error_type
FROM Threads
WHERE isError = 1;
");
        $assistants_stackedbarchart_assistants = $this->select("
SELECT id_assistant, name_assistant
FROM Assistants;
");
        return $this->render('Statistics/statistics_assistants.html.twig', [
            'title' => 'Statistiques des assistants',
            'data_boxplotGroupByHours_assistants' => $data_boxplotGroupByHours_assistants,
            'data_linechart_assistant' => $data_linechart_assistant,
            'data_piechart_assistant' => $data_piechart_assistant,
            'data_boxplot_assistants' => $data_boxplot_assistants,
            'assistants_boxplot_assistants' => $assistants_boxplot_assistants,
            'data_stackedbarchart_assistants' => $data_stackedbarchart_assistants,
            'assistants_stackedbarchart_assistants' => $assistants_stackedbarchart_assistants
        ]);
    }


    /**
     * Affiche une liste de statistiques pour les outils.
     *
     * @return Response La réponse HTTP avec les données des statistiques.
     */
    #[Route('/statistics/tools', name: 'list_statistics_tools', methods: ['GET'])]
    public function list_statistics_tools(): Response
    {
        $nbLogs = $this->select("
SELECT COUNT(*)
FROM Logs;
");
        $data_scatterplot = $this->select("
SELECT DATE_FORMAT(date_log, '%H:%i') AS x_column,
       latency_log AS y_column,
       isError,
       id_log,
       IF(JSON_VALID(params_log), JSON_UNQUOTE(JSON_EXTRACT(params_log, '$.model')), NULL) AS model
FROM Logs
ORDER BY date_log DESC
LIMIT 500;
");
        $model_scatterplot = $this->select("
SELECT JSON_UNQUOTE(JSON_EXTRACT(params_log, '$.model')) AS model
FROM (
    SELECT params_log
    FROM Logs
    WHERE JSON_VALID(params_log)
    ORDER BY date_log DESC
    LIMIT 500
) AS subquery
WHERE JSON_EXTRACT(params_log, '$.model') IS NOT NULL
GROUP BY model
ORDER BY model DESC;
");
        $data_linechart = $this->select("
SELECT
    DATE_FORMAT(date_log, '%Y-%m-%d') AS date,
    MAX(latency_log) AS max_latency,
    MIN(latency_log) AS min_latency,
    (
        SELECT AVG(latency_log)
        FROM (
                 SELECT
                     latency_log,
                     DATE(date_log) AS log_date,
                     ROW_NUMBER() OVER (PARTITION BY DATE(date_log) ORDER BY latency_log) AS row_num,
                     COUNT(*) OVER (PARTITION BY DATE(date_log)) AS total_rows
                 FROM Logs
                 WHERE isError = 0
             ) AS sorted_logs
        WHERE row_num IN (FLOOR((total_rows + 1) / 2), CEIL((total_rows + 1) / 2))
          AND log_date = DATE(outer_logs.date_log)
    ) AS average_latency
FROM Logs AS outer_logs
WHERE isError = 0
GROUP BY DATE(date_log)
ORDER BY date_log;
");
        $tools_linechart = $this->select("
SELECT name_tool, id_tool
FROM Tools;
");
        $data_boxplot = $this->select("
SELECT L.id_tool,
       name_tool,
       latency_log,
       id_log
FROM Logs
         JOIN Languages L on Logs.id_language = L.id_language
         JOIN Tools T on T.id_tool = L.id_tool
ORDER BY date_log DESC
LIMIT 10000;
");
        $tools_boxplot = $this->select("
SELECT name_tool, id_tool
FROM Tools;
");
        $data_groupedbarchart = $this->select("
SELECT
    t.name_tool,
    level,
    AVG(lg.latency_log) AS latency
FROM
    Logs lg
        INNER JOIN
    Languages lang ON lg.id_language = lang.id_language
        INNER JOIN
    Levels l ON lg.id_level = l.id_level
        INNER JOIN
    Tools t ON lang.id_tool = t.id_tool
GROUP BY
    t.id_tool, l.level
ORDER BY l.level DESC;
");
        $data_sunburst = $this->select("
SELECT id_log, ET.id_error_type, name_error_type
FROM Logs
JOIN Errors_Types ET on ET.id_error_type = Logs.id_error_type
WHERE isError = 1;       
");
        $data_sunburstTool = $this->select("
SELECT ET.id_error_type,
       name_error_type,
       L.id_tool,
       name_tool,
       L.id_language,
       name_language,
       L2.id_level,
       name_level
FROM Logs
JOIN Errors_Types ET on ET.id_error_type = Logs.id_error_type
JOIN Languages L on L.id_language = Logs.id_language
JOIN Levels L2 on L2.id_level = Logs.id_level
JOIN Tools T on T.id_tool = L.id_tool
WHERE isError = 1;
");
        $data_stackedbarchart = $this->select("
SELECT date_log,
       ET.id_error_type,
       name_error_type,
       L.id_tool,
       name_tool
FROM Logs
         JOIN Errors_Types ET on Logs.id_error_type = ET.id_error_type
         JOIN Languages L on L.id_language = Logs.id_language
         JOIN Tools T on T.id_tool = L.id_tool
WHERE isError;
");
        $tools_stackedbarchart = $this->select("
SELECT id_tool,
       name_tool
FROM Tools;
");
        $tools_circlepacking = $this->select("
SELECT id_tool, name_tool
FROM Tools;
");
        $languages_circlepacking = $this->select("
SELECT id_language, name_language, id_tool
FROM Languages;
");
        $levels_tools_circlepacking = $this->select("
SELECT id_tool, L.id_level, name_level, level
FROM levels_tools
         JOIN Levels L on L.id_level = levels_tools.id_level;
");
        $logs_circlepacking = $this->select("
SELECT id_log, date_log, id_language, id_level
FROM Logs;
");
        return $this->render('Statistics/statistics_tools.html.twig', [
            'title' => 'Statistiques',
            'nbLogsMax' => $nbLogs[0]['COUNT(*)'],
            'data_scatterplot' => $data_scatterplot,
            'model_scatterplot' => $model_scatterplot,
            'data_linechart' => $data_linechart,
            'tools_linechart' => $tools_linechart,
            'data_boxplot' => $data_boxplot,
            'tools_boxplot' => $tools_boxplot,
            'data_groupedbarchart' => $data_groupedbarchart,
            'data_sunburst' => $data_sunburst,
            'data_sunburstTool' => $data_sunburstTool,
            'data_stackedbarchart' => $data_stackedbarchart,
            'tools_stackedbarchart' => $tools_stackedbarchart,
            'tools_circlepacking' => $tools_circlepacking,
            'languages_circlepacking' => $languages_circlepacking,
            'levels_tools_circlepacking' => $levels_tools_circlepacking,
            'logs_circlepacking' => $logs_circlepacking
        ]);
    }


    /**
     * Récupère les données pour le diagramme de dispersion avec une limite spécifiée.
     *
     * @param Request $request La requête HTTP.
     * @return JsonResponse La réponse JSON avec les données du diagramme de dispersion.
     */
    #[Route('/statistics/scatterplot/{limit}', name: 'statistics_get_data_scatter_plot_filter', methods: ['GET'])]
    public function statistics_get_data_scatter_plot_filter(Request $request): JsonResponse
    {
        try {
            $limit = $request->attributes->get('limit');
            $data_scatterplot = $this->select("
SELECT DATE_FORMAT(date_log, '%H:%i') AS x_column,
       latency_log AS y_column,
       isError,
       id_log,
       IF(JSON_VALID(params_log), JSON_UNQUOTE(JSON_EXTRACT(params_log, '$.model')), NULL) AS model
FROM Logs
ORDER BY date_log DESC
LIMIT $limit;
");
            $model_scatterplot = $this->select("
SELECT JSON_UNQUOTE(JSON_EXTRACT(params_log, '$.model')) AS model
FROM (
    SELECT params_log
    FROM Logs
    WHERE JSON_VALID(params_log)
    ORDER BY date_log DESC
    LIMIT $limit
) AS subquery
WHERE JSON_EXTRACT(params_log, '$.model') IS NOT NULL
GROUP BY model
ORDER BY model DESC;
");
            return new JsonResponse([
                'status' => '200',
                'data' => $data_scatterplot,
                'model' => $model_scatterplot
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => 'Echec de la récupération : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Récupère les données pour le diagramme en boîte avec une plage de dates et une option pour afficher ou non les erreurs.
     *
     * @param Request $request La requête HTTP.
     * @return JsonResponse La réponse JSON avec les données du diagramme en boîte.
     */
    #[Route('/statistics/boxplot/{start}/{end}/{showError}', name: 'statistics_get_data_box_plot_filter', methods: ['GET'])]
    public function statistics_get_data_box_plot_filter(Request $request): JsonResponse
    {
        try {
            $start = str_replace('T', ' ', $request->attributes->get('start'));
            $end = str_replace('T', ' ', $request->attributes->get('end'));
            $showError = $request->attributes->get('showError');
            $baseRequest = "
SELECT L.id_tool,
       name_tool,
       latency_log,
       id_log
FROM Logs
         JOIN Languages L on Logs.id_language = L.id_language
         JOIN Tools T on T.id_tool = L.id_tool
WHERE 1 = 1
";
            if ($showError === 'false') {
                $baseRequest .= " AND isError = 0";
            }
            if ($start !== "null") {
                $baseRequest .= " AND date_log >= '" . $start . "'";
            }
            if ($end !== "null") {
                $baseRequest .= " AND date_log <= '" . $end . "'";
            }
            $baseRequest .= " ORDER BY date_log DESC LIMIT 10000;";
            $data_boxplot = $this->select($baseRequest);
            return new JsonResponse([
                'status' => '200',
                'data' => $data_boxplot,
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => 'Echec de la récupération : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Récupère les données pour le graphique linéaire en fonction de l'outil sélectionné.
     *
     * @param Request $request La requête HTTP.
     * @return JsonResponse La réponse JSON avec les données du graphique linéaire.
     */
    #[Route('/statistics/linechart/{id_tool}', name: 'statistics_get_data_line_chart_filter', methods: ['GET'])]
    public function statistics_get_data_line_chart_filter(Request $request): JsonResponse
    {
        try {
            $id_tool = $request->attributes->get('id_tool');
            if ($id_tool === "all") {
                $data_linechart = $this->select("
SELECT DATE_FORMAT(date_log, '%Y-%m-%d') AS date,
       AVG(latency_log)                  AS average_latency,
       MAX(latency_log)                  AS max_latency,
       MIN(latency_log)                  AS min_latency
FROM Logs
WHERE isError = 0
GROUP BY DATE(date_log)
ORDER BY date_log;
");
            } else {
                $data_linechart = $this->select("
SELECT DATE_FORMAT(date_log, '%Y-%m-%d') AS date,
       AVG(latency_log)                  AS average_latency,
       MAX(latency_log)                  AS max_latency,
       MIN(latency_log)                  AS min_latency
FROM Logs
JOIN Languages L on L.id_language = Logs.id_language
WHERE id_tool = $id_tool AND isError = 0
GROUP BY DATE(date_log);
");
            }
            return new JsonResponse([
                'status' => '200',
                'data' => $data_linechart,
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => 'Echec de la récupération : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Récupère les statistiques pour un langage spécifique.
     *
     * @param Request $request La requête HTTP.
     * @return Response La réponse HTTP avec les statistiques du langage.
     */
    #[Route('/tool/language/statistics/{id_language}', name: 'statistics_language', methods: ['GET'])]
    public function statistics_language(Request $request): Response
    {
        $id_language = $request->attributes->get('id_language');
        $name_language = $this->select("
SELECT name_language, T.id_tool, name_tool, id_language
FROM Languages
JOIN Tools T on Languages.id_tool = T.id_tool
WHERE id_language = $id_language;
");
        $data_linechart = $this->select("
SELECT DATE_FORMAT(date_log, '%Y-%m-%d') AS date,
       AVG(latency_log)                  AS average_latency,
       MAX(latency_log)                  AS max_latency,
       MIN(latency_log)                  AS min_latency
FROM Logs
WHERE id_language = $id_language AND isError = 0
GROUP BY DATE(date_log)
ORDER BY date_log;
");
        $nbLogs = $this->select("
SELECT COUNT(*)
FROM Logs
WHERE id_language = $id_language;
");
        $data_scatterplot = $this->select("
SELECT DATE_FORMAT(date_log, '%H:%i') AS x_column,
       latency_log AS y_column,
       isError,
       id_log,
       name_level AS model
FROM Logs
JOIN Levels L on Logs.id_level = L.id_level
WHERE id_language = $id_language
ORDER BY date_log DESC
LIMIT 500;
");
        $model_scatterplot = $this->select("
SELECT name_level AS model
FROM Levels
JOIN Logs L on Levels.id_level = L.id_level
WHERE L.id_language = $id_language
GROUP BY L.id_level;
");
        return $this->render('Tools/language_statistics.html.twig', [
            'title' => 'Statistiques de ' . $name_language[0]['name_language'],
            'name_language' => $name_language[0]['name_language'],
            'id_language' => $name_language[0]['id_language'],
            'id_tool' => $name_language[0]['id_tool'],
            'name_tool' => $name_language[0]['name_tool'],
            'data_linechart' => $data_linechart,
            'nbLogsMax' => $nbLogs[0]['COUNT(*)'],
            'model_scatterplot' => $model_scatterplot,
            'data_scatterplot' => $data_scatterplot
        ]);
    }
}