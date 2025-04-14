<?php
/**
 * Direct script loader for the plugin.
 *
 * Ensures scripts are loaded in all Elementor contexts.
 *
 * @since      1.1.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direct script loader for the plugin.
 */
class Editing_Time_Tracker_Direct_Loader {

    /**
     * The session manager
     *
     * @var Editing_Time_Tracker_Session_Manager
     */
    private $session_manager;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.1.0
     * @param    Editing_Time_Tracker_Session_Manager    $session_manager    The session manager
     */
    public function __construct($session_manager) {
        $this->session_manager = $session_manager;
        $this->init();
    }

    /**
     * Initialize the direct loader
     *
     * @since    1.1.0
     */
    public function init() {
        // Add script to admin footer
        add_action('admin_footer', array($this, 'inject_elementor_script'));
        
        // Also try to add it to Elementor footer
        add_action('elementor/editor/after_enqueue_scripts', array($this, 'inject_elementor_script'));
        add_action('elementor/editor/footer', array($this, 'inject_elementor_script'));
        
        // Add to frontend footer for Elementor preview
        add_action('wp_footer', array($this, 'inject_elementor_script'));
    }

    /**
     * Inject the Elementor tracking script directly
     *
     * @since    1.1.0
     */
    public function inject_elementor_script() {
        // Only inject if we're in an Elementor context
        if (!$this->is_elementor_context()) {
            return;
        }
        
        // Get post ID
        $post_id = $this->get_current_post_id();
        if (!$post_id) {
            return;
        }
        
        $this->session_manager->debug_log('Injecting Elementor tracking script directly', array(
            'post_id' => $post_id,
            'hook' => current_action()
        ), defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG);
        
        // Output the script directly
        ?>
        <script type="text/javascript">
            // Debug flag
            var ettDebug = <?php echo defined('ETT_ELEMENTOR_DEBUG') && ETT_ELEMENTOR_DEBUG ? 'true' : 'false'; ?>;
            
            // Data for the script
            var ettElementorData = {
                ajaxurl: '<?php echo admin_url('admin-ajax.php'); ?>',
                nonce: '<?php echo wp_create_nonce('ett_elementor_tracking'); ?>',
                post_id: <?php echo $post_id; ?>,
                interval: 60,
                debug: ettDebug,
                timestamp: <?php echo time(); ?>
            };
            
            if (ettDebug) {
                console.log('ETT: Direct script injection');
                console.log('ETT: Data', ettElementorData);
            }
            
            // Wait for jQuery and then load our script
            function ettLoadScript() {
                if (typeof jQuery === 'undefined') {
                    if (ettDebug) {
                        console.log('ETT: jQuery not loaded yet, waiting...');
                    }
                    setTimeout(ettLoadScript, 500);
                    return;
                }
                
                if (ettDebug) {
                    console.log('ETT: jQuery loaded, initializing tracking');
                }
                
                // Variables para seguimiento
                var lastActivity = Date.now();
                var hasChanges = false;
                var isTracking = false;
                var sessionId = 'ett_' + Date.now();
                var activityData = {
                    changes: 0,
                    elements_modified: []
                };
                var saveInProgress = false;
                var timerInterval = null;
                var sessionStartTime = null;
                var buttonWasDisabled = true; // Track if the button was previously disabled
                
                // Variables para el timer visual
                var timerSeconds = 0;
                var timerMinutes = 0;
                var timerHours = 0;
                var $timerDisplay = null;
                
                // Función para registrar actividad
                function trackActivity() {
                    if (!window.elementor) return;
                    
                    lastActivity = Date.now();
                    hasChanges = true;
                    
                    if (ettElementorData.debug) {
                        console.log('ETT: Actividad detectada en el editor');
                    }
                }
                
                // Función para enviar datos de sesión
                function sendSessionData(forceSend = false) {
                    if (!hasChanges && !forceSend) return;
                    
                    if (ettElementorData.debug) {
                        console.log('ETT: Enviando datos de sesión');
                    }
                    
                    // Ensure we have the current duration if timer is running
                    if (sessionStartTime) {
                        var currentDuration = Math.floor((Date.now() - sessionStartTime) / 1000);
                        activityData.duration = currentDuration;
                        
                        if (ettElementorData.debug) {
                            console.log('ETT: Duración actual: ' + currentDuration + ' segundos');
                        }
                    }
                    
                    // Get the current post ID with fallbacks
                    var currentPostId = ettElementorData.post_id;
                    if (!currentPostId && window.elementor && elementor.config && elementor.config.document) {
                        currentPostId = elementor.config.document.id;
                    }
                    if (!currentPostId) {
                        // Try to get from URL
                        var urlParams = new URLSearchParams(window.location.search);
                        if (urlParams.has('post')) {
                            currentPostId = urlParams.get('post');
                        } else if (urlParams.has('editor_post_id')) {
                            currentPostId = urlParams.get('editor_post_id');
                        } else if (urlParams.has('elementor-preview')) {
                            currentPostId = urlParams.get('elementor-preview');
                        }
                    }
                    
                    // Log debug info
                    if (ettElementorData.debug) {
                        console.log('ETT: Sending session data to server', {
                            post_id: currentPostId,
                            session_id: sessionId,
                            has_changes: hasChanges || forceSend,
                            activity_data: activityData
                        });
                    }
                    
                    // Use the new simplified AJAX endpoint
                    jQuery.ajax({
                        url: ettElementorData.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'ett_update_session',
                            post_id: currentPostId,
                            session_id: sessionId,
                            has_changes: hasChanges || forceSend,
                            last_activity: lastActivity,
                            activity_data: JSON.stringify(activityData)
                        },
                        success: function(response) {
                            if (ettElementorData.debug) {
                                console.log('ETT: Server response', response);
                            }
                            
                            if (response.success) {
                                if (!forceSend) {
                                    // Reiniciar contador de cambios solo si no es un envío forzado
                                    hasChanges = false;
                                    activityData.changes = 0;
                                    activityData.elements_modified = [];
                                }
                            } else {
                                // Log error details
                                if (ettElementorData.debug) {
                                    console.error('ETT: Server returned error', response);
                                }
                                
                                // Send debug log
                                sendDebugLog('Server returned error', {
                                    response: response,
                                    post_id: currentPostId,
                                    session_id: sessionId
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            if (ettElementorData.debug) {
                                console.error('ETT: Error sending data', error);
                                console.error('ETT: Response status', status);
                                console.error('ETT: XHR object', xhr);
                            }
                            
                            // Send debug log
                            sendDebugLog('AJAX error', {
                                error: error,
                                status: status,
                                xhr: {
                                    status: xhr.status,
                                    statusText: xhr.statusText,
                                    responseText: xhr.responseText
                                },
                                post_id: currentPostId,
                                session_id: sessionId
                            });
                        }
                    });
                }
                
                // Function to send debug logs to server
                function sendDebugLog(message, data) {
                    if (!ettElementorData.debug) return;
                    
                    jQuery.ajax({
                        url: ettElementorData.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'ett_debug_log',
                            message: message,
                            data: JSON.stringify(data)
                        },
                        success: function(response) {
                            if (ettElementorData.debug) {
                                console.log('ETT: Debug log sent', response);
                            }
                        },
                        error: function() {
                            console.error('ETT: Failed to send debug log');
                        }
                    });
                }
                
                // Función para reiniciar el seguimiento después de guardar
                function resetTrackingAfterSave() {
                    // Generar nuevo ID de sesión
                    sessionId = 'ett_' + Date.now();
                    
                    // Reiniciar datos de seguimiento
                    hasChanges = false;
                    activityData = {
                        changes: 0,
                        elements_modified: []
                    };
                    
                    // Reiniciar el timer para la nueva sesión
                    sessionStartTime = null;
                    timerSeconds = 0;
                    timerMinutes = 0;
                    timerHours = 0;
                    updateTimerDisplay();
                    
                    // Reiniciar el estado de guardado
                    saveInProgress = false;
                    
                    if (ettElementorData.debug) {
                        console.log('ETT: Seguimiento reiniciado para nueva sesión');
                    }
                    
                    // Verificar el estado del botón de publicar inmediatamente
                    checkPublishButton();
                }
                
                // Crear y mostrar el timer visual
                function createTimerDisplay() {
                    // Verificar si ya existe
                    if (jQuery('#ett-timer-display').length) {
                        return jQuery('#ett-timer-display');
                    }
                    
                    // Crear el elemento del timer
                    var $timer = jQuery(`
                        <div id="ett-timer-display" style="
                            position: fixed;
                            top: 50px;
                            right: 20px;
                            background-color: rgba(0, 0, 0, 0.7);
                            color: white;
                            padding: 8px 12px;
                            border-radius: 4px;
                            z-index: 9999;
                            font-family: Arial, sans-serif;
                            font-size: 14px;
                            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
                        ">
                            <div style="display: flex; align-items: center;">
                                <span style="margin-right: 8px;">⏱️</span>
                                <span id="ett-timer-value">00:00:00</span>
                            </div>
                            <div style="font-size: 10px; margin-top: 4px; text-align: center;">Editing Time</div>
                        </div>
                    `);
                    
                    // Añadir al DOM
                    jQuery('body').append($timer);
                    
                    return $timer;
                }
                
                // Actualizar el timer visual
                function updateTimerDisplay() {
                    if (!$timerDisplay) {
                        $timerDisplay = createTimerDisplay();
                    }
                    
                    // Formatear el tiempo
                    var hours = String(timerHours).padStart(2, '0');
                    var minutes = String(timerMinutes).padStart(2, '0');
                    var seconds = String(timerSeconds).padStart(2, '0');
                    
                    // Actualizar el display
                    jQuery('#ett-timer-value').text(`${hours}:${minutes}:${seconds}`);
                }
                
                // Iniciar el timer
                function startTimer() {
                    if (timerInterval) {
                        return; // Ya está corriendo
                    }
                    
                    sessionStartTime = Date.now();
                    timerSeconds = 0;
                    timerMinutes = 0;
                    timerHours = 0;
                    
                    updateTimerDisplay();
                    
                    timerInterval = setInterval(function() {
                        timerSeconds++;
                        
                        if (timerSeconds >= 60) {
                            timerSeconds = 0;
                            timerMinutes++;
                            
                            if (timerMinutes >= 60) {
                                timerMinutes = 0;
                                timerHours++;
                            }
                        }
                        
                        updateTimerDisplay();
                    }, 1000);
                    
                    if (ettElementorData.debug) {
                        console.log('ETT: Timer iniciado');
                    }
                }
                
                // Detener el timer
                function stopTimer() {
                    if (timerInterval) {
                        clearInterval(timerInterval);
                        timerInterval = null;
                        
                        if (ettElementorData.debug) {
                            console.log('ETT: Timer detenido');
                        }
                    }
                }
                
                // Verificar el estado del botón de publicar
                function checkPublishButton() {
                    // Buscar el botón de publicar
                    var $publishButton = jQuery('.MuiButtonGroup-firstButton');
                    
                    if ($publishButton.length) {
                        var isDisabled = $publishButton.prop('disabled') || $publishButton.attr('disabled') === 'disabled';
                        
                        // Si el botón estaba deshabilitado y ahora está habilitado, hay cambios
                        if (buttonWasDisabled && !isDisabled) {
                            if (ettElementorData.debug) {
                                console.log('ETT: Botón de publicar habilitado - detectados cambios');
                            }
                            
                            // Iniciar seguimiento si no está activo
                            if (!isTracking) {
                                initializeTracking();
                            }
                            
                            // Marcar que hay cambios
                            hasChanges = true;
                            trackActivity();
                            activityData.changes++;
                            
                            // Iniciar el timer
                            startTimer();
                        } else if (!buttonWasDisabled && isDisabled) {
                            if (ettElementorData.debug) {
                                console.log('ETT: Botón de publicar deshabilitado - no hay cambios pendientes');
                            }
                        }
                        
                        // Actualizar el estado anterior
                        buttonWasDisabled = isDisabled;
                    }
                }
                
                // Función para inicializar el seguimiento
                function initializeTracking() {
                    if (isTracking) return;
                    
                    // Verificar que Elementor esté disponible
                    if (!window.elementor) {
                        if (ettElementorData.debug) {
                            console.log('ETT: Elementor no está disponible todavía, reintentando en 1 segundo');
                        }
                        setTimeout(initializeTracking, 1000);
                        return;
                    }
                    
                    isTracking = true;
                    
                    // Crear el timer visual
                    createTimerDisplay();
                    
                    // Iniciar verificación periódica del botón de publicar
                    setInterval(checkPublishButton, 1000);
                    
                    // Detectar cambios en el editor
                    elementor.channels.editor.on('change', function() {
                        trackActivity();
                        activityData.changes++;
                        
                        // Capturar el elemento que se está editando
                        if (elementor.channels.editor.request('panel/current-tab')) {
                            var currentElement = elementor.channels.editor.request('panel/current-element');
                            if (currentElement && currentElement.id) {
                                if (!activityData.elements_modified.includes(currentElement.id)) {
                                    activityData.elements_modified.push(currentElement.id);
                                }
                            }
                        }
                    });
                    
                    // Detectar cambios en la estructura
                    elementor.channels.data.on('element:before:add element:before:remove', function() {
                        trackActivity();
                        activityData.changes++;
                    });
                    
                    // Enviar datos periódicamente
                    setInterval(sendSessionData, ettElementorData.interval * 1000);
                    
                    if (ettElementorData.debug) {
                        console.log('ETT: Seguimiento de Elementor iniciado');
                    }
                }
                
                // Iniciar seguimiento cuando Elementor está listo
                jQuery(window).on('elementor:init', initializeTracking);
                
                // También intentar inicializar cuando el documento esté listo
                jQuery(document).ready(function() {
                    // Pequeño retraso para asegurar que Elementor esté completamente cargado
                    setTimeout(initializeTracking, 1000);
                });
                
                // Intentar inicializar también cuando la ventana esté completamente cargada
                jQuery(window).on('load', function() {
                    setTimeout(initializeTracking, 1000);
                });
                
                // Detectar eventos de guardado de Elementor
                jQuery(document).on('click', '.elementor-button-success, .MuiButtonGroup-firstButton', function() {
                    if (ettElementorData.debug) {
                        console.log('ETT: Botón de guardar/publicar detectado');
                    }
                    saveInProgress = true;
                    
                    // Detener el timer
                    stopTimer();
                    
                    // Calcular la duración total
                    if (sessionStartTime) {
                        var duration = Math.floor((Date.now() - sessionStartTime) / 1000);
                        activityData.duration = duration;
                        
                        if (ettElementorData.debug) {
                            console.log('ETT: Duración de la sesión: ' + duration + ' segundos');
                        }
                        
                        // Mark this as a save operation
                        var saveData = {
                            is_save: true,
                            post_id: ettElementorData.post_id,
                            session_id: sessionId,
                            has_changes: true,
                            last_activity: lastActivity,
                            activity_data: JSON.stringify(activityData)
                        };
                        
                        // Get the current post ID with fallbacks
                        if (!saveData.post_id && window.elementor && elementor.config && elementor.config.document) {
                            saveData.post_id = elementor.config.document.id;
                        }
                        if (!saveData.post_id) {
                            // Try to get from URL
                            var urlParams = new URLSearchParams(window.location.search);
                            if (urlParams.has('post')) {
                                saveData.post_id = urlParams.get('post');
                            } else if (urlParams.has('editor_post_id')) {
                                saveData.post_id = urlParams.get('editor_post_id');
                            } else if (urlParams.has('elementor-preview')) {
                                saveData.post_id = urlParams.get('elementor-preview');
                            }
                        }
                        
                        // Send directly to avoid any race conditions
                        jQuery.ajax({
                            url: ettElementorData.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'ett_update_session',
                                is_save: true,
                                post_id: saveData.post_id,
                                session_id: sessionId,
                                has_changes: true,
                                last_activity: lastActivity,
                                activity_data: JSON.stringify(activityData)
                            },
                            success: function(response) {
                                if (ettElementorData.debug) {
                                    console.log('ETT: Save operation recorded', response);
                                }
                            },
                            error: function(xhr, status, error) {
                                if (ettElementorData.debug) {
                                    console.error('ETT: Error recording save operation', error);
                                }
                            }
                        });
                    }
                });
                
                // Detectar cuando se completa un guardado
                jQuery(document).on('elementor/editor/after_save', function() {
                    if (ettElementorData.debug) {
                        console.log('ETT: Evento after_save detectado');
                    }
                    
                    // Forzar el envío de datos actuales
                    sendSessionData(true);
                    
                    // Reiniciar el seguimiento después de un breve retraso
                    // This delay is important to ensure the previous session is recorded properly
                    setTimeout(resetTrackingAfterSave, 1500);
                });
                
                // Also detect document save events
                jQuery(document).on('elementor/document/after_save', function() {
                    if (ettElementorData.debug) {
                        console.log('ETT: Evento document/after_save detectado');
                    }
                    
                    if (!saveInProgress) {
                        saveInProgress = true;
                        
                        // Forzar el envío de datos actuales
                        sendSessionData(true);
                        
                        // Reiniciar el seguimiento después de un breve retraso
                        setTimeout(resetTrackingAfterSave, 1500);
                    }
                });
                
                // Enviar datos al cerrar la ventana
                jQuery(window).on('beforeunload', function() {
                    if ((hasChanges || sessionStartTime) && !saveInProgress) {
                        // Asegurarse de enviar la duración actual
                        if (sessionStartTime) {
                            var duration = Math.floor((Date.now() - sessionStartTime) / 1000);
                            activityData.duration = duration;
                        }
                        sendSessionData(true);
                    }
                });
                
                // Start initialization
                setTimeout(initializeTracking, 1000);
            }
            
            // Start loading the script
            ettLoadScript();
        </script>
        <?php
    }

    /**
     * Check if we're in an Elementor context
     *
     * @since    1.1.0
     * @return   bool    True if we're in an Elementor context
     */
    private function is_elementor_context() {
        // Check for standard Elementor editor
        if (isset($_GET['action']) && $_GET['action'] === 'elementor') {
            return true;
        }
        
        // Check for Elementor preview
        if (isset($_GET['elementor-preview'])) {
            return true;
        }
        
        // Check if we're in an Elementor AJAX request
        if (defined('DOING_AJAX') && DOING_AJAX && 
            isset($_POST['action']) && 
            (strpos($_POST['action'], 'elementor') !== false)) {
            return true;
        }
        
        // Check if Elementor is active via global
        if (did_action('elementor/loaded') || class_exists('\\Elementor\\Plugin')) {
            // Additional check for admin screens
            if (is_admin() && isset($_GET['post'])) {
                $post_id = (int)$_GET['post'];
                if (get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder') {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Get the current post ID
     *
     * @since    1.1.0
     * @return   int    The current post ID
     */
    private function get_current_post_id() {
        // Get post ID from various sources
        $post_id = 0;
        
        if (isset($_GET['post'])) {
            $post_id = (int)$_GET['post'];
        } elseif (isset($_GET['editor_post_id'])) {
            $post_id = (int)$_GET['editor_post_id'];
        } elseif (isset($_GET['elementor-preview'])) {
            $post_id = (int)$_GET['elementor-preview'];
        } elseif (isset($_POST['post_id'])) {
            $post_id = (int)$_POST['post_id'];
        } elseif (isset($_POST['editor_post_id'])) {
            $post_id = (int)$_POST['editor_post_id'];
        }
        
        // If no post ID found, try to get from referer
        if (!$post_id && isset($_SERVER['HTTP_REFERER'])) {
            $referer_parts = parse_url($_SERVER['HTTP_REFERER']);
            if (isset($referer_parts['query'])) {
                parse_str($referer_parts['query'], $query_vars);
                if (isset($query_vars['post'])) {
                    $post_id = (int)$query_vars['post'];
                } else if (isset($query_vars['editor_post_id'])) {
                    $post_id = (int)$query_vars['editor_post_id'];
                }
            }
        }
        
        return $post_id;
    }
}
