using System.Net;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;

namespace AertsActionBike.EidBridge;

internal static class Program
{
    public static async Task<int> Main()
    {
        Console.OutputEncoding = Encoding.UTF8;
        Console.Title = "Aerts Action Bike eID Bridge";

        var port = 17895;
        if (int.TryParse(Environment.GetEnvironmentVariable("AAB_EID_BRIDGE_PORT"), out var configuredPort)
            && configuredPort is > 1024 and < 65536)
        {
            port = configuredPort;
        }

        var allowedOrigins = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
        {
            "https://warrevandermaat.be",
            "https://www.warrevandermaat.be",
            "http://localhost:8080",
            "http://127.0.0.1:8080",
        };

        var configuredOrigins = Environment.GetEnvironmentVariable("AAB_EID_ALLOWED_ORIGINS");
        if (!string.IsNullOrWhiteSpace(configuredOrigins))
        {
            allowedOrigins.Clear();
            foreach (var origin in configuredOrigins.Split(';', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
            {
                allowedOrigins.Add(origin);
            }
        }

        using var backend = new BelgianEidBackend();
        try
        {
            backend.Start();
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine("De Belgische eID Viewer-backend kon niet worden gestart.");
            Console.Error.WriteLine(ex.Message);
            Console.Error.WriteLine("Installeer de officiële Belgische eID Middleware én eID Viewer en start daarna de bridge opnieuw.");
            return 2;
        }

        using var cts = new CancellationTokenSource();
        Console.CancelKeyPress += (_, args) =>
        {
            args.Cancel = true;
            cts.Cancel();
        };

        var server = new LoopbackServer(port, backend, allowedOrigins);
        Console.WriteLine($"AAB eID Bridge actief op http://127.0.0.1:{port}");
        Console.WriteLine("Alleen loopbackverkeer wordt aanvaard. Sluit dit venster om de bridge te stoppen.");
        Console.WriteLine($"eID backend: {backend.BackendPath}");

        try
        {
            await server.RunAsync(cts.Token);
        }
        catch (OperationCanceledException)
        {
            // Normal shutdown.
        }

        return 0;
    }
}

internal sealed class LoopbackServer
{
    private readonly TcpListener _listener;
    private readonly BelgianEidBackend _backend;
    private readonly HashSet<string> _allowedOrigins;
    private readonly JsonSerializerOptions _jsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
    };

    public LoopbackServer(int port, BelgianEidBackend backend, HashSet<string> allowedOrigins)
    {
        _listener = new TcpListener(IPAddress.Loopback, port);
        _backend = backend;
        _allowedOrigins = allowedOrigins;
    }

    public async Task RunAsync(CancellationToken cancellationToken)
    {
        _listener.Start();
        try
        {
            while (!cancellationToken.IsCancellationRequested)
            {
                var client = await _listener.AcceptTcpClientAsync(cancellationToken);
                _ = Task.Run(() => HandleClientAsync(client, cancellationToken), CancellationToken.None);
            }
        }
        finally
        {
            _listener.Stop();
        }
    }

    private async Task HandleClientAsync(TcpClient client, CancellationToken cancellationToken)
    {
        using (client)
        using (var stream = client.GetStream())
        using (var reader = new StreamReader(stream, Encoding.ASCII, detectEncodingFromByteOrderMarks: false, bufferSize: 8192, leaveOpen: true))
        {
            try
            {
                var requestLine = await reader.ReadLineAsync();
                if (string.IsNullOrWhiteSpace(requestLine)) return;

                var parts = requestLine.Split(' ', 3, StringSplitOptions.RemoveEmptyEntries);
                if (parts.Length < 2)
                {
                    await WriteJsonAsync(stream, 400, new { ok = false, error = "Ongeldig HTTP-verzoek." }, null);
                    return;
                }

                var method = parts[0].ToUpperInvariant();
                var target = parts[1];
                var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);

                for (var i = 0; i < 100; i++)
                {
                    var line = await reader.ReadLineAsync();
                    if (line is null || line.Length == 0) break;
                    if (line.Length > 8192) throw new InvalidOperationException("HTTP-header te lang.");
                    var separator = line.IndexOf(':');
                    if (separator > 0)
                    {
                        headers[line[..separator].Trim()] = line[(separator + 1)..].Trim();
                    }
                }

                headers.TryGetValue("Origin", out var origin);
                if (!string.IsNullOrWhiteSpace(origin) && !_allowedOrigins.Contains(origin))
                {
                    await WriteJsonAsync(stream, 403, new { ok = false, error = "Deze website mag de lokale eID bridge niet gebruiken." }, null);
                    return;
                }

                if (method == "OPTIONS")
                {
                    await WriteEmptyAsync(stream, 204, origin);
                    return;
                }

                if (method != "GET")
                {
                    await WriteJsonAsync(stream, 405, new { ok = false, error = "Alleen GET en OPTIONS zijn toegestaan." }, origin);
                    return;
                }

                if (!Uri.TryCreate("http://127.0.0.1" + target, UriKind.Absolute, out var uri))
                {
                    await WriteJsonAsync(stream, 400, new { ok = false, error = "Ongeldige route." }, origin);
                    return;
                }

                if (uri.AbsolutePath.Equals("/v1/health", StringComparison.OrdinalIgnoreCase))
                {
                    var health = _backend.GetHealth();
                    await WriteJsonAsync(stream, 200, new
                    {
                        ok = true,
                        service = "AAB eID Bridge",
                        backendLoaded = true,
                        cardPresent = health.CardPresent,
                        readers = health.Readers,
                    }, origin);
                    return;
                }

                if (uri.AbsolutePath.Equals("/v1/read", StringComparison.OrdinalIgnoreCase))
                {
                    var timeout = Math.Clamp(GetQueryInt(uri.Query, "timeout") ?? 12000, 1000, 30000);
                    try
                    {
                        var identity = await _backend.ReadAsync(timeout, cancellationToken);
                        await WriteJsonAsync(stream, 200, new
                        {
                            ok = true,
                            reader = identity.Reader,
                            readAt = identity.ReadAt,
                            identity = new
                            {
                                firstNames = identity.FirstNames,
                                surname = identity.Surname,
                                fullName = identity.FullName,
                                street = identity.Street,
                                postalCode = identity.PostalCode,
                                municipality = identity.Municipality,
                                address = identity.Address,
                                validUntil = identity.ValidUntil,
                            },
                        }, origin);
                    }
                    catch (EidReadException ex)
                    {
                        await WriteJsonAsync(stream, ex.StatusCode, new { ok = false, error = ex.Message }, origin);
                    }
                    return;
                }

                await WriteJsonAsync(stream, 404, new { ok = false, error = "Route niet gevonden." }, origin);
            }
            catch (Exception ex)
            {
                try
                {
                    await WriteJsonAsync(stream, 500, new { ok = false, error = "Lokale eID bridge fout: " + ex.Message }, null);
                }
                catch
                {
                    // Connection already gone.
                }
            }
        }
    }

    private static int? GetQueryInt(string query, string key)
    {
        if (string.IsNullOrWhiteSpace(query)) return null;
        foreach (var pair in query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var parts = pair.Split('=', 2);
            if (Uri.UnescapeDataString(parts[0]).Equals(key, StringComparison.OrdinalIgnoreCase)
                && parts.Length == 2
                && int.TryParse(Uri.UnescapeDataString(parts[1]), out var value))
            {
                return value;
            }
        }
        return null;
    }

    private async Task WriteJsonAsync(NetworkStream stream, int statusCode, object payload, string? origin)
    {
        var json = JsonSerializer.Serialize(payload, _jsonOptions);
        await WriteResponseAsync(stream, statusCode, "application/json; charset=utf-8", Encoding.UTF8.GetBytes(json), origin);
    }

    private static Task WriteEmptyAsync(NetworkStream stream, int statusCode, string? origin)
        => WriteResponseAsync(stream, statusCode, "text/plain; charset=utf-8", Array.Empty<byte>(), origin);

    private static async Task WriteResponseAsync(NetworkStream stream, int statusCode, string contentType, byte[] body, string? origin)
    {
        var reason = statusCode switch
        {
            200 => "OK",
            204 => "No Content",
            400 => "Bad Request",
            403 => "Forbidden",
            404 => "Not Found",
            405 => "Method Not Allowed",
            408 => "Request Timeout",
            409 => "Conflict",
            500 => "Internal Server Error",
            _ => "OK",
        };

        var headers = new StringBuilder();
        headers.Append($"HTTP/1.1 {statusCode} {reason}\r\n");
        headers.Append($"Content-Type: {contentType}\r\n");
        headers.Append($"Content-Length: {body.Length}\r\n");
        headers.Append("Cache-Control: no-store\r\n");
        headers.Append("Connection: close\r\n");
        headers.Append("Access-Control-Allow-Methods: GET, OPTIONS\r\n");
        headers.Append("Access-Control-Allow-Headers: Accept, Content-Type\r\n");
        headers.Append("Access-Control-Allow-Private-Network: true\r\n");
        headers.Append("Cross-Origin-Resource-Policy: cross-origin\r\n");
        if (!string.IsNullOrWhiteSpace(origin))
        {
            headers.Append($"Access-Control-Allow-Origin: {origin}\r\n");
            headers.Append("Vary: Origin\r\n");
        }
        headers.Append("\r\n");

        var headerBytes = Encoding.ASCII.GetBytes(headers.ToString());
        await stream.WriteAsync(headerBytes);
        if (body.Length > 0) await stream.WriteAsync(body);
        await stream.FlushAsync();
    }
}

internal sealed class BelgianEidBackend : IDisposable
{
    private const int SourceNone = 0;
    private const int SourceFile = 1;
    private const int SourceCard = 2;
    private const int SourceUnknown = 3;

    private const int StateToken = 3;
    private const int StateTokenWait = 4;
    private const int StateTokenError = 9;
    private const int StateCardInvalid = 13;
    private const int StateNoToken = 14;
    private const int StateNoReader = 15;
    private const int StateTokenIdle = 16;

    private readonly object _sync = new();
    private readonly Dictionary<string, string> _fields = new(StringComparer.Ordinal);
    private readonly List<string> _readers = new();
    private bool _cardPresent;
    private bool _readComplete;
    private DateTimeOffset? _readAt;
    private nint _libraryHandle;

    private readonly NewSourceCallback _newSource;
    private readonly NewStringDataCallback _newStringData;
    private readonly NewBinaryDataCallback _newBinaryData;
    private readonly LogCallback _log;
    private readonly NewStateCallback _newState;
    private readonly PinResultCallback _pinResult;
    private readonly ReadersChangedCallback _readersChanged;
    private readonly ChallengeResultCallback _challengeResult;

    public string BackendPath { get; private set; } = string.Empty;

    public BelgianEidBackend()
    {
        _newSource = OnNewSource;
        _newStringData = OnNewStringData;
        _newBinaryData = (_, _, _) => { };
        _log = (_, _) => { };
        _newState = OnNewState;
        _pinResult = (_, _) => { };
        _readersChanged = OnReadersChanged;
        _challengeResult = (_, _, _) => { };
    }

    public void Start()
    {
        if (!OperatingSystem.IsWindows())
        {
            throw new PlatformNotSupportedException("De AAB eID Bridge werkt alleen op Windows.");
        }

        BackendPath = FindBackendDll()
            ?? throw new FileNotFoundException("eIDViewerBackend.dll werd niet gevonden onder 'Belgium Identity Card\\EidViewer'.");

        var backendDirectory = Path.GetDirectoryName(BackendPath)
            ?? throw new InvalidOperationException("De map van de eID Viewer-backend kon niet worden bepaald.");

        SetDllDirectory(backendDirectory);
        _libraryHandle = NativeLibrary.Load(BackendPath);

        var setCallbacks = Marshal.GetDelegateForFunctionPointer<SetCallbacksDelegate>(
            NativeLibrary.GetExport(_libraryHandle, "eid_vwr_set_cbfuncs"));

        setCallbacks(
            _newSource,
            _newStringData,
            _newBinaryData,
            _log,
            _newState,
            _pinResult,
            _readersChanged,
            _challengeResult);

        if (NativeLibrary.TryGetExport(_libraryHandle, "eid_vwr_be_select_slot", out var selectSlotPointer))
        {
            var selectSlot = Marshal.GetDelegateForFunctionPointer<SelectSlotDelegate>(selectSlotPointer);
            selectSlot(1, 0); // Automatic reader selection, same mode as the official viewer.
        }
    }

    public EidHealth GetHealth()
    {
        lock (_sync)
        {
            return new EidHealth(_cardPresent, _readers.ToArray());
        }
    }

    public async Task<EidIdentity> ReadAsync(int timeoutMs, CancellationToken cancellationToken)
    {
        var deadline = DateTimeOffset.UtcNow.AddMilliseconds(timeoutMs);
        while (DateTimeOffset.UtcNow < deadline)
        {
            cancellationToken.ThrowIfCancellationRequested();
            lock (_sync)
            {
                if (_readers.Count == 0 && DateTimeOffset.UtcNow > deadline.AddMilliseconds(-Math.Max(1000, timeoutMs - 1000)))
                {
                    // Give the backend a short startup window before reporting no reader.
                }

                if (_cardPresent && _readComplete && HasRequiredFields())
                {
                    return BuildIdentity();
                }
            }

            await Task.Delay(120, cancellationToken);
        }

        lock (_sync)
        {
            if (_readers.Count == 0)
            {
                throw new EidReadException(409, "Geen kaartlezer gevonden. Controleer of de DIGIPASS 905 aangesloten is en door Windows/eID Viewer wordt herkend.");
            }
            if (!_cardPresent)
            {
                throw new EidReadException(408, "Geen Belgische eID gedetecteerd. Steek de kaart volledig in de DIGIPASS 905 en probeer opnieuw.");
            }
            throw new EidReadException(408, "De eID werd niet tijdig volledig uitgelezen. Verwijder de kaart, steek ze opnieuw in en probeer nogmaals.");
        }
    }

    private void OnNewSource(int source)
    {
        lock (_sync)
        {
            if (source == SourceCard)
            {
                ClearIdentityData();
                _cardPresent = true;
            }
            else if (source is SourceNone or SourceUnknown or SourceFile)
            {
                ClearIdentityData();
                _cardPresent = false;
            }
        }
    }

    private void OnNewStringData(string? label, string? data)
    {
        if (string.IsNullOrWhiteSpace(label) || data is null) return;

        // Data minimisation: deliberately ignore national_number, photo/card/chip identifiers,
        // birth data, gender, nationality and all other callbacks we do not need for rentals.
        if (label is not (
            "firstnames" or
            "surname" or
            "address_street_and_number" or
            "address_zip" or
            "address_municipality" or
            "validity_end_date"))
        {
            return;
        }

        lock (_sync)
        {
            _fields[label] = data.Trim();
        }
    }

    private void OnNewState(int state)
    {
        lock (_sync)
        {
            if (state == StateToken)
            {
                _cardPresent = true;
                _readComplete = false;
                return;
            }

            if (state is StateTokenWait or StateTokenIdle)
            {
                _cardPresent = true;
                _readComplete = true;
                _readAt = DateTimeOffset.UtcNow;
                return;
            }

            if (state is StateNoToken or StateNoReader or StateTokenError or StateCardInvalid)
            {
                ClearIdentityData();
                _cardPresent = false;
            }
        }
    }

    private void OnReadersChanged(uint readerCount, IntPtr slotList)
    {
        lock (_sync)
        {
            _readers.Clear();
            if (readerCount == 0 || slotList == IntPtr.Zero) return;

            var structSize = Marshal.SizeOf<EidSlotDesc>();
            for (var i = 0; i < readerCount; i++)
            {
                var pointer = IntPtr.Add(slotList, checked((int)(i * (uint)structSize)));
                var slot = Marshal.PtrToStructure<EidSlotDesc>(pointer);
                var description = slot.Description == IntPtr.Zero ? null : Marshal.PtrToStringUni(slot.Description);
                if (!string.IsNullOrWhiteSpace(description) && !description.Contains("PnP", StringComparison.OrdinalIgnoreCase))
                {
                    _readers.Add(description.Trim());
                }
            }
        }
    }

    private bool HasRequiredFields()
        => _fields.TryGetValue("firstnames", out var firstNames) && !string.IsNullOrWhiteSpace(firstNames)
        && _fields.TryGetValue("surname", out var surname) && !string.IsNullOrWhiteSpace(surname)
        && _fields.TryGetValue("address_street_and_number", out var street) && !string.IsNullOrWhiteSpace(street)
        && _fields.TryGetValue("address_zip", out var postalCode) && !string.IsNullOrWhiteSpace(postalCode)
        && _fields.TryGetValue("address_municipality", out var municipality) && !string.IsNullOrWhiteSpace(municipality);

    private EidIdentity BuildIdentity()
    {
        var firstNames = GetField("firstnames");
        var surname = GetField("surname");
        var street = GetField("address_street_and_number");
        var postalCode = GetField("address_zip");
        var municipality = GetField("address_municipality");
        var validUntil = GetField("validity_end_date");
        var fullName = string.Join(' ', new[] { firstNames, surname }.Where(value => !string.IsNullOrWhiteSpace(value)));
        var locality = string.Join(' ', new[] { postalCode, municipality }.Where(value => !string.IsNullOrWhiteSpace(value)));
        var address = string.Join(", ", new[] { street, locality }.Where(value => !string.IsNullOrWhiteSpace(value)));

        return new EidIdentity(
            firstNames,
            surname,
            fullName,
            street,
            postalCode,
            municipality,
            address,
            validUntil,
            _readers.FirstOrDefault() ?? "Smartcard reader",
            (_readAt ?? DateTimeOffset.UtcNow).ToString("O"));
    }

    private string GetField(string key) => _fields.TryGetValue(key, out var value) ? value : string.Empty;

    private void ClearIdentityData()
    {
        _fields.Clear();
        _readComplete = false;
        _readAt = null;
    }

    private static string? FindBackendDll()
    {
        var configured = Environment.GetEnvironmentVariable("AAB_EID_BACKEND_DLL");
        if (!string.IsNullOrWhiteSpace(configured) && File.Exists(configured)) return Path.GetFullPath(configured);

        var roots = new[]
        {
            Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles),
            Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86),
        }
        .Where(path => !string.IsNullOrWhiteSpace(path))
        .Distinct(StringComparer.OrdinalIgnoreCase);

        foreach (var root in roots)
        {
            var direct = Path.Combine(root, "Belgium Identity Card", "EidViewer", "eIDViewerBackend.dll");
            if (File.Exists(direct)) return direct;

            var belgiumDir = Path.Combine(root, "Belgium Identity Card");
            if (!Directory.Exists(belgiumDir)) continue;
            try
            {
                var found = Directory.EnumerateFiles(belgiumDir, "eIDViewerBackend.dll", SearchOption.AllDirectories).FirstOrDefault();
                if (found is not null) return found;
            }
            catch (UnauthorizedAccessException)
            {
                // Continue with other known locations.
            }
        }

        return null;
    }

    public void Dispose()
    {
        if (_libraryHandle != 0)
        {
            NativeLibrary.Free(_libraryHandle);
            _libraryHandle = 0;
        }
        SetDllDirectory(null);
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct EidSlotDesc
    {
        public uint Slot;
        public IntPtr Description;
    }

    [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
    private delegate void NewSourceCallback(int source);

    [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
    private delegate void NewStringDataCallback(
        [MarshalAs(UnmanagedType.LPWStr)] string? label,
        [MarshalAs(UnmanagedType.LPWStr)] string? data);

    [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
    private delegate void NewBinaryDataCallback(
        [MarshalAs(UnmanagedType.LPWStr)] string? label,
        IntPtr data,
        int dataLength);

    [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
    private delegate void LogCallback(int level, [MarshalAs(UnmanagedType.LPWStr)] string? message);

    [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
    private delegate void NewStateCallback(int state);

    [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
    private delegate void PinResultCallback(int operation, int result);

    [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
    private delegate void ReadersChangedCallback(uint readerCount, IntPtr slotList);

    [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
    private delegate void ChallengeResultCallback(IntPtr data, int dataLength, int result);

    [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
    private delegate int SetCallbacksDelegate(
        NewSourceCallback newSource,
        NewStringDataCallback newStringData,
        NewBinaryDataCallback newBinaryData,
        LogCallback log,
        NewStateCallback newState,
        PinResultCallback pinResult,
        ReadersChangedCallback readersChanged,
        ChallengeResultCallback challengeResult);

    [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
    private delegate void SelectSlotDelegate(int automatic, uint manualSlot);

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetDllDirectory(string? lpPathName);
}

internal sealed record EidHealth(bool CardPresent, string[] Readers);

internal sealed record EidIdentity(
    string FirstNames,
    string Surname,
    string FullName,
    string Street,
    string PostalCode,
    string Municipality,
    string Address,
    string ValidUntil,
    string Reader,
    string ReadAt);

internal sealed class EidReadException : Exception
{
    public int StatusCode { get; }

    public EidReadException(int statusCode, string message) : base(message)
    {
        StatusCode = statusCode;
    }
}
