<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Language strings for Drive Resource.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Drive Resource';
$string['modulename'] = 'Drive Resource';
$string['modulenameplural'] = 'Drive Resources';
$string['modulename_help'] = 'Use esta actividad para insertar videos, PDF, imágenes, documentos, hojas de cálculo y presentaciones desde Google Drive o almacenamiento protegido local.';
$string['pluginadministration'] = 'Administración de Drive Resource';
$string['resourcename'] = 'Nombre del recurso';
$string['videoname'] = 'Nombre del recurso';
$string['resourcesource'] = 'Origen del recurso';
$string['sourcegoogledrive'] = 'Google Drive';
$string['sourcelocalpdf'] = 'PDF local protegido';
$string['driveurl'] = 'URL de Google Drive';
$string['driveurl_help'] = 'Pegue una URL compartible de Google Drive o Google Docs.';
$string['videourl'] = 'URL de Google Drive';
$string['videourl_help'] = 'Pegue una URL compartible de Google Drive o Google Docs.';
$string['localpdffile'] = 'Archivo PDF local';
$string['localpdffile_help'] = 'Suba un único archivo PDF. El archivo se almacena en Moodle y se entrega únicamente mediante las verificaciones de acceso de Drive Resource.';
$string['resourcetype'] = 'Tipo de recurso';
$string['disabledownload'] = 'Desactivar descarga';
$string['disabledownload_help'] = 'Oculta acciones de descarga y sirve el recurso en línea mediante el proxy protegido.';
$string['disablecontextmenu'] = 'Desactivar clic derecho y acciones básicas de copia';
$string['enablewatermark'] = 'Mostrar marca de agua dinámica';
$string['gamification'] = 'Gamificación';
$string['enablegamification'] = 'Activar gamificación de lectura';
$string['enablegamification_help'] = 'Otorga hitos y puntos personales conforme el estudiante avanza en el recurso.';
$string['pointsperpage'] = 'Puntos por avance';
$string['completionpercentage'] = 'Porcentaje requerido de finalización';
$string['completionpercentage_help'] = 'Porcentaje necesario para considerar este recurso como completado.';
$string['protectedresource'] = 'Recurso protegido';
$string['backtoresource'] = 'Volver al recurso';
$string['progress'] = 'Progreso';
$string['points'] = 'Puntos';
$string['progressreport'] = 'Reporte de progreso';
$string['noprogressrecords'] = 'Todavía no hay registros de progreso para este recurso.';
$string['noresources'] = 'No hay Drive Resources en este curso.';
$string['noresourcesavailable'] = 'No hay Drive Resources disponibles para usted en este curso.';
$string['typeauto'] = 'Automático';
$string['typevideo'] = 'Video';
$string['typeaudio'] = 'Audio';
$string['typepdf'] = 'PDF';
$string['typeimage'] = 'Imagen';
$string['typedocument'] = 'Documento';
$string['typespreadsheet'] = 'Hoja de cálculo';
$string['typepresentation'] = 'Presentación';
$string['typefile'] = 'Archivo';
$string['invalidurl'] = 'La URL proporcionada no es válida.';
$string['invaliddriveurl'] = 'Ingrese una URL válida de Google Drive o Google Docs.';
$string['requiredlocalpdf'] = 'Suba un archivo PDF local.';
$string['invalidlocalpdf'] = 'Solo se permiten archivos PDF para este origen.';
$string['invalidcompletionpercentage'] = 'El porcentaje de finalización debe estar entre 0 y 100.';
$string['invalidpointsperpage'] = 'Los puntos deben estar entre 0 y 100.';
$string['unsupportedprotectedresource'] = 'Este tipo de recurso protegido no está soportado actualmente.';
$string['protectedresourceunavailable'] = 'El recurso protegido no está disponible actualmente.';
$string['pdfjsrequired'] = 'No se pudo cargar el visor local PDF.js.';
$string['loadingpdf'] = 'Cargando PDF...';
$string['previouspage'] = 'Anterior';
$string['nextpage'] = 'Siguiente';
$string['fullscreen'] = 'Pantalla completa';
$string['resumereading'] = 'Continuar desde la página';
$string['rewardfirstpage'] = 'Lectura iniciada';
$string['rewardpercent'] = 'Hito del {$a}% alcanzado';
$string['rewardcompleted'] = 'Recurso completado';
$string['eventprogressupdated'] = 'Progreso de Drive Resource actualizado';
$string['eventresourcecompleted'] = 'Drive Resource completado';
$string['eventrewardawarded'] = 'Recompensa de Drive Resource otorgada';
$string['videonotsupported'] = 'Su navegador no puede reproducir este video.';
$string['setting_enabletracking'] = 'Activar seguimiento de progreso';
$string['setting_enabletracking_desc'] = 'Registra permanencia e interacción del usuario.';
$string['setting_defaultrequiredseconds'] = 'Tiempo requerido predeterminado';
$string['setting_defaultrequiredseconds_desc'] = 'Tiempo activo predeterminado en segundos.';
$string['setting_defaultcompletionpercentage'] = 'Porcentaje de finalización predeterminado';
$string['setting_defaultcompletionpercentage_desc'] = 'Porcentaje usado por defecto al crear actividades.';
$string['setting_showresourcetype'] = 'Mostrar tipo de recurso';
$string['setting_showresourcetype_desc'] = 'Muestra el tipo de recurso detectado.';
$string['setting_playercolormode'] = 'Modo de color del reproductor';
$string['setting_playercolormode_desc'] = 'Usa el color principal del tema Moodle cuando sea posible, o fuerza un color HEX personalizado para el reproductor HTML5 integrado.';
$string['setting_playercolormode_theme'] = 'Usar color del tema Moodle';
$string['setting_playercolormode_custom'] = 'Usar color HEX personalizado';
$string['setting_playercolor'] = 'Color personalizado del reproductor';
$string['setting_playercolor_desc'] = 'Color HEX usado cuando el modo de color del reproductor es personalizado. Ejemplo: #3b82f6.';
$string['setting_pdfcacheenabled'] = 'Activar caché PDF';
$string['setting_pdfcacheenabled_desc'] = 'Guarda temporalmente PDFs protegidos en la caché local de Moodle para acelerar futuras vistas.';
$string['setting_pdfcachettl'] = 'Duración de caché PDF';
$string['setting_pdfcachettl_desc'] = 'Duración en segundos para los PDFs cacheados.';
$string['task_cleanup_pdf_cache'] = 'Limpiar caché PDF de Drive Resource';
$string['mod_videoplayer:addinstance'] = 'Agregar un nuevo Drive Resource';
$string['mod_videoplayer:addinstance_help'] = 'Permite agregar una nueva actividad Drive Resource al curso.';
$string['mod_videoplayer:view'] = 'Ver Drive Resource';
$string['mod_videoplayer:view_help'] = 'Permite a usuarios autenticados y matriculados ver el contenido protegido de Drive Resource.';
$string['mod_videoplayer:edit'] = 'Editar Drive Resource';
$string['mod_videoplayer:edit_help'] = 'Permite editar la configuración de Drive Resource.';
$string['mod_videoplayer:manage'] = 'Gestionar Drive Resource';
$string['mod_videoplayer:manage_help'] = 'Permite gestionar la configuración de Drive Resource.';
$string['mod_videoplayer:viewreport'] = 'Ver reportes de Drive Resource';
$string['mod_videoplayer:viewreport_help'] = 'Permite ver reportes relacionados con Drive Resource.';
$string['mod_videoplayer:editreport'] = 'Editar reportes de Drive Resource';
$string['mod_videoplayer:editreport_help'] = 'Permite editar reportes y progreso de usuarios en Drive Resource.';
$string['privacy:metadata:videoplayer_views'] = 'Almacena datos de progreso y finalización.';
$string['privacy:metadata:videoplayer_views:videoplayerid'] = 'ID de la instancia.';
$string['privacy:metadata:videoplayer_views:userid'] = 'ID del usuario.';
$string['privacy:metadata:videoplayer_views:progress'] = 'Último progreso guardado.';
$string['privacy:metadata:videoplayer_views:completed'] = 'Indica si fue completado.';
$string['privacy:metadata:videoplayer_views:completionpercentage'] = 'Porcentaje guardado.';
$string['privacy:metadata:videoplayer_views:lastpage'] = 'Última página alcanzada.';
$string['privacy:metadata:videoplayer_views:totalpages'] = 'Total de páginas detectadas.';
$string['privacy:metadata:videoplayer_views:timespent'] = 'Tiempo activo de lectura.';
$string['privacy:metadata:videoplayer_views:points'] = 'Puntos acumulados.';
$string['privacy:metadata:videoplayer_views:timecreated'] = 'Fecha de creación.';
$string['privacy:metadata:videoplayer_views:timemodified'] = 'Fecha de actualización.';
$string['privacy:metadata:videoplayer_rewards'] = 'Almacena recompensas de gamificación.';
$string['privacy:metadata:videoplayer_rewards:videoplayerid'] = 'ID de la instancia.';
$string['privacy:metadata:videoplayer_rewards:userid'] = 'ID del usuario.';
$string['privacy:metadata:videoplayer_rewards:rewardtype'] = 'Tipo de recompensa.';
$string['privacy:metadata:videoplayer_rewards:rewardkey'] = 'Clave de recompensa.';
$string['privacy:metadata:videoplayer_rewards:points'] = 'Puntos otorgados.';
$string['privacy:metadata:videoplayer_rewards:timecreated'] = 'Fecha de obtención.';
$string['videoloading'] = 'Cargando video…';

$string['videoplay'] = 'Reproducir';
$string['videopause'] = 'Pausar';
$string['videoseek'] = 'Buscar en el video';
$string['videomute'] = 'Silenciar';
$string['videounmute'] = 'Activar sonido';
$string['videovolume'] = 'Volumen';
$string['videoerror'] = 'No se pudo cargar el video';
$string['videoerrorhelp'] = 'El flujo protegido no está disponible temporalmente. Inténtelo nuevamente.';
$string['retry'] = 'Reintentar';


$string['audionotsupported'] = 'Su navegador no puede reproducir este audio.';
$string['videospeed'] = 'Velocidad de reproducción';
$string['searchpdf'] = 'Buscar en el documento';
$string['nextmatch'] = 'Siguiente coincidencia';
$string['searching'] = 'Buscando…';
$string['nomatches'] = 'Sin coincidencias';
$string['zoomin'] = 'Acercar';
$string['zoomout'] = 'Alejar';
$string['fitscreen'] = 'Ajustar a pantalla';
$string['genericresourcehelp'] = 'Este archivo se entrega a través de Moodle. La URL original de Google Drive no se expone.';
$string['openprotectedresource'] = 'Abrir recurso protegido';
$string['privacy:metadata:videoplayer_views:lastposition'] = 'Posición exacta guardada para reanudar el recurso multimedia, en segundos.';
$string['privacy:metadata:videoplayer_views:duration'] = 'Última duración detectada del recurso multimedia, en segundos.';
$string['lastposition'] = 'Posición de reanudación';
$string['duration'] = 'Duración';
$string['timespent'] = 'Tiempo activo';
