const chatBox = document.getElementById("chatBox");
const userInput = document.getElementById("userInput");

function agregarMensaje(texto, tipo) {
    const mensaje = document.createElement("div");
    mensaje.classList.add("message");
    
    // Soporta múltiples clases separadas por espacio
    tipo.split(" ").forEach(clase => {
        if (clase) mensaje.classList.add(clase);
    });

    if (tipo.includes("loading")) {
        mensaje.innerHTML = "EVA está pensando";
    } else {
        mensaje.textContent = texto;
    }

    chatBox.appendChild(mensaje);
    chatBox.scrollTop = chatBox.scrollHeight;

    return mensaje;
}

async function enviarMensaje() {
    const texto = userInput.value.trim();

    if (texto === "") return;

    agregarMensaje(texto, "user");
    userInput.value = "";

    const mensajeCargando = agregarMensaje("", "bot loading");

    try {
        const response = await fetch("api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ mensaje: texto })
        });

        const data = await response.json();
        mensajeCargando.remove();

        if (data.error) {
            agregarMensaje("⚠️ " + data.error, "bot");
            if (data.detalle) console.error(data.detalle);
            return;
        }

        agregarMensaje(data.respuesta, "bot");

    } catch (error) {
        mensajeCargando.remove();
        agregarMensaje("❌ Error de conexión. Revisa tu servidor local.", "bot");
        console.error(error);
    }
}

userInput.addEventListener("keydown", (e) => {
    if (e.key === "Enter") enviarMensaje();
});