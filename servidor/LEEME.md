# Cosas del servidor que no son la aplicación

## `fabos-hora` — el reloj, cuando NTP está bloqueado

Muchas redes institucionales dejan salir HTTPS y poco más. Si bloquean NTP
(UDP 123), `chronyd` se queda con todas sus fuentes en `Reach 0`, el reloj se
va yendo, y **el segundo factor deja de funcionar**: TOTP compara ventanas de
30 segundos, así que un minuto de desfase invalida cualquier código.

Cómo se ve el problema:

```bash
timedatectl                 # System clock synchronized: no
chronyc sources             # todas las fuentes con Reach 0
```

Y cómo se comprueba el desfase real:

```bash
curl -sI https://cloudflare.com/ | grep -i ^date   # la hora de verdad
date -u                                            # la del servidor
```

Instalación, como root:

```bash
install -m 755 servidor/fabos-hora /usr/local/sbin/fabos-hora
install -m 644 servidor/fabos-hora.service /etc/systemd/system/
install -m 644 servidor/fabos-hora.timer   /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now fabos-hora.timer
```

Esto **no sustituye a NTP**. Si la red deja salir el 123, `chronyd` vuelve a
tomar el mando y el temporizador se queda sin trabajo. La precisión de la
cabecera `Date` es de un segundo más la latencia: mucho peor que NTP, y
suficiente de sobra para TOTP, para las reservas y para los registros.
