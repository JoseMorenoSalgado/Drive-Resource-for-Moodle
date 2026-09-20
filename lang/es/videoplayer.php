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
 * Spanish language strings for Drive Resource.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['backtoresource'] = 'Volver al recurso';
$string['completionpercentage'] = 'Porcentaje requerido de finalización';
$string['completionpercentage_help'] = 'Porcentaje necesario para completar el recurso. En PDF se calcula con páginas realmente vistas y en video con rangos de reproducción realmente vistos.';
$string['disablecontextmenu'] = 'Desactivar clic derecho y acciones básicas de copia';
$string['disabledownload'] = 'Desactivar descarga';
$string['disabledownload_help'] = 'Oculta acciones de descarga y sirve el recurso en línea mediante el proxy protegido.';
$string['displaymode'] = 'Modo de visualización PDF';
$string['displaymode_help'] = 'Los recursos PDF utilizan el visor local estable PDF.js.';
$string['displaymodeebook'] = 'Visor tipo libro heredado';
$string['displaymodestandard'] = 'Visor PDF.js estándar';
$string['driveurl'] = 'URL de Google Drive';
$string['driveurl_help'] = 'Pegue una URL compartible de Google Drive o Google Docs.';
$string['enablegamification'] = 'Activar gamificación de lectura';
$string['enablegamification_help'] = 'Otorga hitos y puntos personales conforme el estudiante avanza en el recurso protegido.';
$string['enablewatermark'] = 'Mostrar marca de agua dinámica';
$string['eventprogressupdated'] = 'Progreso de Drive Resource actualizado';
$string['eventresourcecompleted'] = 'Drive Resource completado';
$string['eventrewardawarded'] = 'Recompensa de Drive Resource otorgada';
$string['fittoscreen'] = 'Ajustar a la pantalla';
$string['fullscreen'] = 'Pantalla completa';
$string['gamification'] = 'Gamificación';
$string['invalidcompletionpercentage'] = 'El porcentaje de finalización debe estar entre 0 y 100.';
$string['invaliddriveurl'] = 'Ingrese una URL válida de Google Drive o Google Docs.';
$string['invalidlocalpdf'] = 'Solo se permiten archivos PDF para este origen.';
$string['invalidpointsperpage'] = 'Los puntos deben estar entre 0 y 100.';
$string['invalidurl'] = 'La URL proporcionada no es válida.';
$string['lastposition'] = 'Última posición';
$string['loadingpdf'] = 'Cargando PDF...';
$string['localpdffile'] = 'Archivo PDF local';
$string['localpdffile_help'] = 'Suba un único archivo PDF. El archivo se almacena en Moodle y se entrega únicamente mediante las verificaciones de acceso de Drive Resource.';
$string['mod_videoplayer:addinstance'] = 'Agregar un nuevo Drive Resource';
$string['mod_videoplayer:addinstance_help'] = 'Permite agregar una nueva actividad Drive Resource al curso.';
$string['mod_videoplayer:edit'] = 'Editar Drive Resource';
$string['mod_videoplayer:edit_help'] = 'Permite editar la configuración de Drive Resource.';
$string['mod_videoplayer:editreport'] = 'Editar reportes de Drive Resource';
$string['mod_videoplayer:editreport_help'] = 'Permite editar reportes y progreso de usuarios en Drive Resource.';
$string['mod_videoplayer:manage'] = 'Gestionar Drive Resource';
$string['mod_videoplayer:manage_help'] = 'Permite gestionar la configuración de Drive Resource.';
$string['mod_videoplayer:view'] = 'Ver Drive Resource';
$string['mod_videoplayer:view_help'] = 'Permite a usuarios autenticados y matriculados ver el contenido protegido de Drive Resource.';
$string['mod_videoplayer:viewreport'] = 'Ver reportes de Drive Resource';
$string['mod_videoplayer:viewreport_help'] = 'Permite ver reportes relacionados con Drive Resource.';
$string['modulename'] = 'Drive Resource';
$string['modulename_help'] = 'Use esta actividad para insertar videos, PDF, imágenes, documentos, hojas de cálculo y presentaciones desde Google Drive o almacenamiento protegido local.';
$string['modulenameplural'] = 'Drive Resources';
$string['nextpage'] = 'Siguiente';
$string['noprogressrecords'] = 'Todavía no hay registros de progreso para este recurso.';
$string['noresources'] = 'No hay Drive Resources en este curso.';
$string['noresourcesavailable'] = 'No hay Drive Resources disponibles para usted en este curso.';
$string['pageposition'] = 'Página {$a->page} de {$a->total}';
$string['pagesviewed'] = '{$a} páginas vistas';
$string['pdfjsrequired'] = 'No se pudo cargar el visor local PDF.js.';
$string['pluginadministration'] = 'Administración de Drive Resource';
$string['pluginname'] = 'Drive Resource';
$string['plyrmissing'] = 'No se pudo cargar el reproductor local Plyr. Se usará el reproductor HTML5 nativo.';
$string['points'] = 'Puntos';
$string['pointsperpage'] = 'Puntos por avance';
$string['previouspage'] = 'Anterior';
$string['privacy:metadata:videoplayer_rewards'] = 'Almacena recompensas de gamificación.';
$string['privacy:metadata:videoplayer_rewards:points'] = 'Puntos otorgados.';
$string['privacy:metadata:videoplayer_rewards:rewardkey'] = 'Clave de recompensa.';
$string['privacy:metadata:videoplayer_rewards:rewardtype'] = 'Tipo de recompensa.';
$string['privacy:metadata:videoplayer_rewards:timecreated'] = 'Fecha de obtención.';
$string['privacy:metadata:videoplayer_rewards:userid'] = 'ID del usuario.';
$string['privacy:metadata:videoplayer_rewards:videoplayerid'] = 'ID de la instancia.';
$string['privacy:metadata:videoplayer_views'] = 'Almacena datos de progreso y finalización.';
$string['privacy:metadata:videoplayer_views:completed'] = 'Indica si fue completado.';
$string['privacy:metadata:videoplayer_views:completionpercentage'] = 'Porcentaje guardado.';
$string['privacy:metadata:videoplayer_views:lastpage'] = 'Última página alcanzada.';
$string['privacy:metadata:videoplayer_views:lastsecond'] = 'Último segundo de reproducción guardado para reanudar el video.';
$string['privacy:metadata:videoplayer_views:points'] = 'Puntos acumulados.';
$string['privacy:metadata:videoplayer_views:progress'] = 'Último progreso guardado.';
$string['privacy:metadata:videoplayer_views:timecreated'] = 'Fecha de creación.';
$string['privacy:metadata:videoplayer_views:timemodified'] = 'Fecha de actualización.';
$string['privacy:metadata:videoplayer_views:timespent'] = 'Tiempo activo de lectura.';
$string['privacy:metadata:videoplayer_views:totalpages'] = 'Total de páginas detectadas.';
$string['privacy:metadata:videoplayer_views:totalseconds'] = 'Duración total del video usada para calcular el progreso.';
$string['privacy:metadata:videoplayer_views:userid'] = 'ID del usuario.';
$string['privacy:metadata:videoplayer_views:videoplayerid'] = 'ID de la instancia.';
$string['privacy:metadata:videoplayer_views:visitedpages'] = 'Conjunto de páginas PDF vistas por el usuario para calcular la finalización exacta.';
$string['privacy:metadata:videoplayer_views:watchedranges'] = 'Rangos normalizados del video realmente vistos por el usuario.';
$string['progress'] = 'Progreso';
$string['progressreport'] = 'Reporte de progreso';
$string['protectedmodedisabled'] = 'El modo protegido está desactivado.';
$string['protectedresource'] = 'Recurso protegido';
$string['protectedresourceunavailable'] = 'El recurso protegido no está disponible actualmente.';
$string['requiredlocalpdf'] = 'Suba un archivo PDF local.';
$string['resourcename'] = 'Nombre del recurso';
$string['resourcesource'] = 'Origen del recurso';
$string['resourcetype'] = 'Tipo de recurso';
$string['resourcetype_help'] = 'Seleccione el tipo de recurso de forma explícita para enlaces estándar de Google Drive /file/d/. El modo automático identifica Documentos, Hojas de cálculo, Presentaciones y URLs con extensión visible; en los demás casos usa Video para mantener compatibilidad con el complemento Video Player original.';
$string['resumereading'] = 'Continuar desde la página';
$string['rewardcompleted'] = 'Recurso completado';
$string['rewardfirstpage'] = 'Lectura iniciada';
$string['rewardpercent'] = 'Hito del {$a}% alcanzado';
$string['searchpdf'] = 'Buscar';
$string['searchpdfnotfound'] = 'No se encontró texto coincidente.';
$string['searchpdfplaceholder'] = 'Buscar texto en este documento';
$string['searchpdfresultpage'] = 'Resultado en la página {$a}';
$string['secondposition'] = 'Segundo {$a->second} de {$a->total}';
$string['secondswatched'] = '{$a} segundos vistos';
$string['setting_defaultcompletionpercentage'] = 'Porcentaje de finalización predeterminado';
$string['setting_defaultcompletionpercentage_desc'] = 'Porcentaje usado por defecto al crear actividades.';
$string['setting_defaultrequiredseconds'] = 'Tiempo requerido predeterminado';
$string['setting_defaultrequiredseconds_desc'] = 'Tiempo activo predeterminado en segundos.';
$string['setting_enabletracking'] = 'Activar seguimiento de progreso';
$string['setting_enabletracking_desc'] = 'Registra progreso exacto e interacción activa en los recursos compatibles.';
$string['setting_pdfcacheenabled'] = 'Activar caché PDF';
$string['setting_pdfcacheenabled_desc'] = 'Guarda temporalmente PDFs protegidos en la caché local de Moodle para acelerar futuras vistas.';
$string['setting_pdfcachettl'] = 'Duración de caché PDF';
$string['setting_pdfcachettl_desc'] = 'Duración en segundos para los PDF almacenados en caché.';
$string['setting_playercolor'] = 'Color personalizado del reproductor';
$string['setting_playercolor_desc'] = 'Color HEX usado cuando el modo de color del reproductor es personalizado. Ejemplo: #3b82f6.';
$string['setting_playercolormode'] = 'Modo de color del reproductor';
$string['setting_playercolormode_custom'] = 'Usar color HEX personalizado';
$string['setting_playercolormode_desc'] = 'Usa el color principal del tema Moodle cuando sea posible, o fuerza un color HEX personalizado para el reproductor Plyr.';
$string['setting_playercolormode_theme'] = 'Usar color del tema Moodle';
$string['setting_protectedmode'] = 'Modo protegido';
$string['setting_protectedmode_desc'] = 'Entrega los recursos compatibles mediante visores protegidos propios de Moodle, sin la interfaz de vista previa de Google Drive.';
$string['setting_showresourcetype'] = 'Mostrar tipo de recurso';
$string['setting_showresourcetype_desc'] = 'Muestra el tipo de recurso detectado.';
$string['sourcegoogledrive'] = 'Google Drive';
$string['sourcelocalpdf'] = 'PDF local protegido';
$string['task_cleanup_pdf_cache'] = 'Limpiar caché PDF de Drive Resource';
$string['timespent'] = 'Tiempo activo';
$string['typeauto'] = 'Automático (video por defecto)';
$string['typedocument'] = 'Documento';
$string['typefile'] = 'Archivo';
$string['typeimage'] = 'Imagen';
$string['typepdf'] = 'PDF';
$string['typepresentation'] = 'Presentación';
$string['typespreadsheet'] = 'Hoja de cálculo';
$string['typevideo'] = 'Video';
$string['unsupportedprotectedresource'] = 'Este tipo de recurso protegido no está soportado actualmente.';
$string['videojsmissing'] = 'Video.js local no está instalado. Se usará el reproductor HTML5 nativo.';
$string['videoname'] = 'Nombre del recurso';
$string['videonotsupported'] = 'Su navegador no puede reproducir este video.';
$string['videoerrorcodec'] = 'El video protegido llegó correctamente al navegador, pero su códec no es compatible con la reproducción HTML5. Para máxima compatibilidad use MP4 con video H.264/AVC y audio AAC.';
$string['videoerrornetwork'] = 'Drive Resource no pudo obtener un flujo de bytes reproducible desde Google Drive. Verifique que el archivo esté compartido para que pueda abrirse mediante el enlace e inténtelo nuevamente.';
$string['videoerrorgeneric'] = 'No se pudo reproducir el video. Recargue la actividad o solicite al administrador revisar el diagnóstico del streaming protegido.';
$string['videoplayer:addinstance'] = 'Agregar un nuevo Drive Resource';
$string['videourl'] = 'URL de Google Drive';
$string['videourl_help'] = 'Pegue una URL compartible de Google Drive o Google Docs.';
$string['zoomin'] = 'Acercar';
$string['zoomout'] = 'Alejar';
