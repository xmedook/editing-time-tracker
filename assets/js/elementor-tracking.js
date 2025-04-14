(function($) {
    'use strict';
    
    // Variables para seguimiento
    let lastActivity = Date.now();
    let hasChanges = false;
    let isTracking = false;
    let sessionId = 'ett_' + Date.now();
    let activityData = {
        changes: 0,
        elements_modified: []
    };
    let saveInProgress = false;
    let timerInterval = null;
    let sessionStartTime = null;
    let buttonWasDisabled = true; // Track if the button was previously disabled
    
    // Variables para el timer visual
    let timerSeconds = 0;
    let timerMinutes = 0;
    let timerHours = 0;
    let $timerDisplay = null;
    
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
    function sendSessionData() {
        if (!hasChanges) return;
        
        if (ettElementorData.debug) {
            console.log('ETT: Enviando datos de sesión');
        }
        
        $.ajax({
            url: ettElementorData.ajaxurl,
            type: 'POST',
            data: {
                action: 'ett_update_elementor_session',
                nonce: ettElementorData.nonce,
                post_id: ettElementorData.post_id || elementor.config.document.id,
                session_id: sessionId,
                has_changes: hasChanges,
                last_activity: lastActivity,
                activity_data: JSON.stringify(activityData)
            },
            success: function(response) {
                if (ettElementorData.debug) {
                    console.log('ETT: Respuesta del servidor', response);
                }
                
                // Reiniciar contador de cambios
                hasChanges = false;
                activityData.changes = 0;
                activityData.elements_modified = [];
            }
        });
    }
    
    // Función para iniciar una nueva sesión después de guardar
    function startNewSessionAfterSave() {
        // Generar nuevo ID de sesión
        sessionId = 'ett_' + Date.now();
        
        // Reiniciar datos de seguimiento
        hasChanges = false;
        activityData = {
            changes: 0,
            elements_modified: []
        };
        
        // Forzar una actualización de sesión para iniciar una nueva
        $.ajax({
            url: ettElementorData.ajaxurl,
            type: 'POST',
            data: {
                action: 'ett_start_new_session',
                nonce: ettElementorData.nonce,
                post_id: ettElementorData.post_id || (window.elementor ? elementor.config.document.id : 0),
                session_id: sessionId,
                timestamp: Date.now() // Add timestamp to ensure uniqueness
            },
            success: function(response) {
                if (ettElementorData.debug) {
                    console.log('ETT: Nueva sesión iniciada después de guardar', response);
                }
                
                // Start tracking changes again
                lastActivity = Date.now();
                saveInProgress = false;
                
                if (ettElementorData.debug) {
                    console.log('ETT: Seguimiento reiniciado con nueva sesión');
                }
            },
            error: function() {
                if (ettElementorData.debug) {
                    console.log('ETT: Error al iniciar nueva sesión');
                }
                saveInProgress = false;
            }
        });
    }
    
    // Crear y mostrar el timer visual
    function createTimerDisplay() {
        // Verificar si ya existe
        if ($('#ett-timer-display').length) {
            return $('#ett-timer-display');
        }
        
        // Crear el elemento del timer
        const $timer = $(`
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
        $('body').append($timer);
        
        return $timer;
    }
    
    // Actualizar el timer visual
    function updateTimerDisplay() {
        if (!$timerDisplay) {
            $timerDisplay = createTimerDisplay();
        }
        
        // Formatear el tiempo
        const hours = String(timerHours).padStart(2, '0');
        const minutes = String(timerMinutes).padStart(2, '0');
        const seconds = String(timerSeconds).padStart(2, '0');
        
        // Actualizar el display
        $('#ett-timer-value').text(`${hours}:${minutes}:${seconds}`);
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
        const $publishButton = $('.MuiButtonGroup-firstButton');
        
        if ($publishButton.length) {
            const isDisabled = $publishButton.prop('disabled') || $publishButton.attr('disabled') === 'disabled';
            
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
                let currentElement = elementor.channels.editor.request('panel/current-element');
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
        
        // Iniciar una nueva sesión si Elementor ya está cargado
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            startNewSessionAfterSave();
        }
        
        if (ettElementorData.debug) {
            console.log('ETT: Seguimiento de Elementor iniciado');
        }
    }
    
    // Iniciar seguimiento cuando Elementor está listo
    $(window).on('elementor:init', initializeTracking);
    
    // También intentar inicializar cuando el documento esté listo
    $(document).ready(function() {
        // Pequeño retraso para asegurar que Elementor esté completamente cargado
        setTimeout(initializeTracking, 1000);
    });
    
    // Intentar inicializar también cuando la ventana esté completamente cargada
    $(window).on('load', function() {
        setTimeout(initializeTracking, 1000);
    });
    
    // Detectar eventos de guardado de Elementor
    $(document).on('click', '.elementor-button-success, .MuiButtonGroup-firstButton', function() {
        if (ettElementorData.debug) {
            console.log('ETT: Botón de guardar/publicar detectado');
        }
        saveInProgress = true;
        
        // Detener el timer
        stopTimer();
        
        // Calcular la duración total
        if (sessionStartTime) {
            const duration = Math.floor((Date.now() - sessionStartTime) / 1000);
            activityData.duration = duration;
            
            if (ettElementorData.debug) {
                console.log('ETT: Duración de la sesión: ' + duration + ' segundos');
            }
        }
    });
    
    // Detectar cuando se completa un guardado
    $(document).on('elementor/editor/after_save', function() {
        if (ettElementorData.debug) {
            console.log('ETT: Evento after_save detectado');
        }
        
        // Enviar datos actuales antes de iniciar nueva sesión
        if (hasChanges) {
            sendSessionData();
        }
        
        // Iniciar nueva sesión después de un breve retraso
        // This delay is important to ensure the previous session is recorded properly
        setTimeout(function() {
            startNewSessionAfterSave();
            
            // Reiniciar el timer para la nueva sesión
            sessionStartTime = null;
            timerSeconds = 0;
            timerMinutes = 0;
            timerHours = 0;
            updateTimerDisplay();
        }, 1000);
    });
    
    // Also detect document save events
    $(document).on('elementor/document/after_save', function() {
        if (ettElementorData.debug) {
            console.log('ETT: Evento document/after_save detectado');
        }
        
        if (!saveInProgress) {
            saveInProgress = true;
            
            // Enviar datos actuales antes de iniciar nueva sesión
            if (hasChanges) {
                sendSessionData();
            }
            
            // Iniciar nueva sesión después de un breve retraso
            setTimeout(startNewSessionAfterSave, 1000);
        }
    });
    
    // Enviar datos al cerrar la ventana
    $(window).on('beforeunload', function() {
        if (hasChanges && !saveInProgress) {
            sendSessionData();
        }
    });
    
})(jQuery);
