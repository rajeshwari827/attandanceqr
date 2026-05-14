/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React, { useState, useEffect, useRef } from 'react';
import { 
  LayoutDashboard, 
  PlusCircle, 
  ListFilter, 
  Menu, 
  X, 
  LogIn, 
  LogOut, 
  ChevronRight, 
  ChevronLeft, 
  Calendar, 
  MapPin, 
  FileText, 
  Download, 
  Copy, 
  CheckCircle2,
  Trash2,
  Plus,
  ArrowRight,
  QrCode,
  Scan,
  UserCheck,
  Printer,
  Mail,
  Settings,
  Save,
  Send,
  Info,
  Eye,
  EyeOff
} from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';
import { QRCodeCanvas } from 'qrcode.react';
import { Html5QrcodeScanner } from 'html5-qrcode';
import * as XLSX from 'xlsx';
import { format, isAfter, isBefore } from 'date-fns';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';
import { Toaster, toast } from 'sonner';
import { Event, FormElement, FormElementType, Registration } from './types';

function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// --- Components ---

const HeroSlider = ({ events, onAction, actionLabel, isAdmin = false }: { 
  events: Event[], 
  onAction: (e: Event) => void, 
  actionLabel: string,
  isAdmin?: boolean
}) => {
  const [currentSlide, setCurrentSlide] = useState(0);
  const latestEvents = events.slice(0, 3);

  useEffect(() => {
    if (latestEvents.length <= 1) return;
    const timer = setInterval(() => {
      setCurrentSlide(prev => (prev + 1) % latestEvents.length);
    }, 5000);
    return () => clearInterval(timer);
  }, [latestEvents.length]);

  if (latestEvents.length === 0) return null;

  return (
    <div className="relative h-[400px] rounded-3xl overflow-hidden shadow-2xl bg-slate-900">
      <AnimatePresence mode="wait">
        <motion.div
          key={currentSlide}
          initial={{ opacity: 0, x: 50 }}
          animate={{ opacity: 1, x: 0 }}
          exit={{ opacity: 0, x: -50 }}
          className="absolute inset-0"
        >
          {latestEvents[currentSlide].brochureUrl ? (
            <img 
              src={latestEvents[currentSlide].brochureUrl} 
              alt={latestEvents[currentSlide].name} 
              className="w-full h-full object-cover opacity-60"
            />
          ) : (
            <div className="w-full h-full bg-gradient-to-br from-primary to-accent opacity-40" />
          )}
          <div className="absolute inset-0 flex flex-col justify-center p-12 text-white">
            <span className="bg-accent text-white text-xs font-bold px-3 py-1 rounded-full w-fit mb-4 uppercase tracking-widest">
              {isAdmin ? 'Admin Dashboard' : 'Recently Added'}
            </span>
            <h1 className="text-5xl font-bold mb-4 max-w-2xl">{latestEvents[currentSlide].name}</h1>
            <p className="text-lg text-slate-200 mb-8 max-w-xl line-clamp-2">{latestEvents[currentSlide].description}</p>
            <div className="flex gap-4">
              <button 
                onClick={() => onAction(latestEvents[currentSlide])}
                className="btn-accent px-8 py-3 text-lg flex items-center gap-2"
              >
                {isAdmin ? <FileText className="w-5 h-5" /> : <PlusCircle className="w-5 h-5" />}
                {actionLabel}
              </button>
            </div>
          </div>
        </motion.div>
      </AnimatePresence>
      
      {latestEvents.length > 1 && (
        <div className="absolute bottom-6 left-1/2 -translate-x-1/2 flex gap-2">
          {latestEvents.map((_, i) => (
            <button 
              key={i} 
              onClick={() => setCurrentSlide(i)}
              className={cn(
                "w-2 h-2 rounded-full transition-all",
                currentSlide === i ? "bg-white w-8" : "bg-white/40"
              )}
            />
          ))}
        </div>
      )}
    </div>
  );
};

const QRScanner = ({ onScanSuccess, scanning = true }: { onScanSuccess: (decodedText: string) => void, scanning?: boolean }) => {
  const [isCameraActive, setIsCameraActive] = useState(false);
  const scannerRef = useRef<Html5QrcodeScanner | null>(null);
  const callbackRef = useRef(onScanSuccess);
  
  useEffect(() => {
    callbackRef.current = onScanSuccess;
  }, [onScanSuccess]);

  useEffect(() => {
    if (!scanning) {
      if (scannerRef.current) {
        scannerRef.current.clear().catch(e => console.error("Failed to clear", e));
        scannerRef.current = null;
        setIsCameraActive(false);
      }
      return;
    }

    const scanner = new Html5QrcodeScanner(
      "reader",
      { 
        fps: 10, 
        qrbox: { width: 250, height: 250 },
        aspectRatio: 1.0
      },
      /* verbose= */ false
    );

    scanner.render(
      (text) => {
        callbackRef.current(text);
      },
      (error) => {
        // Silent errors
      }
    );

    scannerRef.current = scanner;
    setIsCameraActive(true);

    return () => {
      if (scannerRef.current) {
        scannerRef.current.clear().catch(error => console.error("Failed to clear scanner", error));
        scannerRef.current = null;
      }
    };
  }, [scanning]);

  return (
    <div className="w-full">
      <div className="relative overflow-hidden rounded-2xl bg-slate-900 border-4 border-slate-100 shadow-xl">
        <div id="reader" className="w-full"></div>
        {!isCameraActive && (
          <div className="absolute inset-0 flex items-center justify-center bg-slate-900/80 backdrop-blur-sm">
            <div className="text-center p-6">
              <div className="w-16 h-16 bg-primary/20 rounded-full flex items-center justify-center mx-auto mb-4">
                <Scan className="w-8 h-8 text-primary animate-pulse" />
              </div>
              <p className="text-white font-medium">Initializing Camera...</p>
            </div>
          </div>
        )}
        <div className="absolute top-4 left-4 right-4 flex justify-between items-center pointer-events-none">
          <div className="px-3 py-1 bg-black/50 backdrop-blur-md rounded-full text-[10px] font-bold text-white uppercase tracking-widest flex items-center gap-2">
            <div className="w-2 h-2 bg-emerald-500 rounded-full animate-pulse" />
            Live Scanner
          </div>
        </div>
      </div>
      <div className="mt-6 text-center space-y-2">
        <p className="text-slate-500 text-sm font-medium">Position the QR code within the frame</p>
        <div className="flex justify-center gap-4">
          <div className="flex items-center gap-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
            <div className="w-1.5 h-1.5 bg-slate-300 rounded-full" />
            Auto-Focus
          </div>
          <div className="flex items-center gap-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
            <div className="w-1.5 h-1.5 bg-slate-300 rounded-full" />
            Instant Verify
          </div>
        </div>
      </div>
    </div>
  );
};

const Receipt = ({ registration, event, onBack }: { registration: Registration, event: Event, onBack: () => void }) => {
  const receiptRef = useRef<HTMLDivElement>(null);

  const handlePrint = () => {
    window.print();
  };

  // Unique QR data: registrationId-eventId
  const qrData = JSON.stringify({
    regId: registration.id,
    eventId: event.id
  });

  return (
    <div className="max-w-2xl mx-auto p-4 sm:p-8">
      <motion.div 
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        className="glass-card overflow-hidden"
        ref={receiptRef}
      >
        <div className="bg-primary p-8 text-white text-center print:bg-white print:text-black">
          <div className="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center mx-auto mb-4 print:hidden">
            <CheckCircle2 className="w-10 h-10 text-white" />
          </div>
          <h2 className="text-3xl font-bold mb-2">Registration Successful!</h2>
          <p className="text-white/80">Thank you for registering for {event.name}</p>
        </div>

        <div className="p-8 space-y-8">
          <div className="flex flex-col md:flex-row justify-between gap-8">
            <div className="space-y-4">
              <div>
                <p className="text-[10px] uppercase tracking-widest font-bold text-slate-400">Registration ID</p>
                <p className="text-lg font-mono font-bold text-primary">#REG-{registration.id.toString().padStart(5, '0')}</p>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-widest font-bold text-slate-400">Event</p>
                <p className="text-lg font-bold text-slate-800">{event.name}</p>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-widest font-bold text-slate-400">Venue</p>
                <p className="text-slate-600">{event.venue}</p>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-widest font-bold text-slate-400">Date & Time</p>
                <p className="text-slate-600">{format(new Date(event.startDate), 'PPP p')}</p>
              </div>
            </div>

            <div className="flex flex-col items-center justify-center bg-slate-50 p-6 rounded-2xl border border-slate-100">
              <QRCodeCanvas 
                value={qrData} 
                size={160}
                level="H"
                includeMargin={true}
                className="rounded-lg shadow-sm"
              />
              <p className="mt-4 text-[10px] uppercase tracking-widest font-bold text-slate-400 text-center">
                Unique Entry Pass
              </p>
            </div>
          </div>

          <div className="border-t border-slate-100 pt-8">
            <h4 className="font-bold text-slate-800 mb-4">Participant Details</h4>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              {Object.entries(registration.formData).map(([key, value]) => (
                <div key={key}>
                  <p className="text-[10px] uppercase tracking-widest font-bold text-slate-400">{key}</p>
                  <p className="text-sm text-slate-600 truncate">
                    {typeof value === 'string' && value.startsWith('data:image') ? 'Image Uploaded' : String(value)}
                  </p>
                </div>
              ))}
            </div>
          </div>

          <div className="bg-amber-50 border border-amber-100 p-4 rounded-xl flex gap-3 print:hidden">
            <QrCode className="w-5 h-5 text-amber-600 shrink-0" />
            <p className="text-xs text-amber-800 leading-relaxed">
              Please save this receipt or take a screenshot. You will need to show the QR code at the venue for attendance verification.
            </p>
          </div>

          <div className="flex flex-col sm:flex-row gap-4 print:hidden">
            <button 
              onClick={handlePrint}
              className="btn-primary flex-1 flex items-center justify-center gap-2"
            >
              <Printer className="w-5 h-5" />
              Print Receipt
            </button>
            <button 
              onClick={async () => {
                try {
                  const res = await fetch(`/api/registrations/${registration.id}/resend-email`, { method: 'POST' });
                  if (res.ok) {
                    toast.success("Receipt sent to your email!");
                  } else {
                    toast.error("Failed to send email. Please check your connection.");
                  }
                } catch (err) {
                  toast.error("Error connecting to server.");
                }
              }}
              className="bg-slate-100 text-slate-600 px-8 py-3 rounded-full font-semibold hover:bg-slate-200 transition-all flex-1 flex items-center justify-center gap-2"
            >
              <Mail className="w-5 h-5" />
              Send to Email
            </button>
            <button 
              onClick={onBack}
              className="bg-slate-100 text-slate-600 px-8 py-3 rounded-full font-semibold hover:bg-slate-200 transition-all flex-1"
            >
              Back to Events
            </button>
          </div>
        </div>
      </motion.div>
    </div>
  );
};

const Navbar = ({ 
  isAdmin, 
  onLoginClick, 
  onLogout, 
  onToggleSidebar, 
  showSidebarToggle,
  onLogoClick
}: { 
  isAdmin: boolean; 
  onLoginClick: () => void; 
  onLogout: () => void;
  onToggleSidebar?: () => void;
  showSidebarToggle?: boolean;
  onLogoClick?: () => void;
}) => (
  <nav className="bg-white border-b border-slate-100 sticky top-0 z-50">
    <div className="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between">
      <div className="flex items-center gap-4">
        {showSidebarToggle && (
          <button onClick={onToggleSidebar} className="p-2 hover:bg-slate-100 rounded-lg transition-colors">
            <Menu className="w-6 h-6 text-primary" />
          </button>
        )}
        <button 
          onClick={onLogoClick}
          className="flex flex-col text-left hover:opacity-80 transition-opacity"
        >
          <span className="font-bold text-xl text-primary tracking-tight">CHRIST COLLEGE</span>
          <span className="text-[10px] uppercase tracking-widest text-accent font-semibold -mt-1">Rajkot</span>
        </button>
      </div>
      
      <div className="flex items-center gap-4">
        {isAdmin ? (
          <div className="flex items-center gap-3">
            <span className="text-sm font-medium text-slate-600 hidden sm:block">Admin Panel</span>
            <button 
              onClick={onLogout}
              className="flex items-center gap-2 text-sm font-semibold text-red-600 hover:bg-red-50 px-4 py-2 rounded-full transition-all"
            >
              <LogOut className="w-4 h-4" />
              Logout
            </button>
          </div>
        ) : (
          <button 
            onClick={onLoginClick}
            className="flex items-center gap-2 text-sm font-semibold text-primary hover:bg-primary/5 px-4 py-2 rounded-full transition-all"
          >
            <LogIn className="w-4 h-4" />
            Admin Login
          </button>
        )}
      </div>
    </div>
  </nav>
);

const SubNavbar = ({ onExplore }: { onExplore: () => void }) => (
  <div className="bg-primary/5 border-b border-primary/10">
    <div className="max-w-7xl mx-auto px-4 h-12 flex items-center">
      <button 
        onClick={onExplore}
        className="text-sm font-semibold text-primary flex items-center gap-2 hover:gap-3 transition-all"
      >
        Explore Events
        <ArrowRight className="w-4 h-4" />
      </button>
    </div>
  </div>
);

const Sidebar = ({ 
  isOpen, 
  onClose, 
  activeTab, 
  setActiveTab 
}: { 
  isOpen: boolean; 
  onClose: () => void; 
  activeTab: string; 
  setActiveTab: (tab: string) => void;
}) => (
  <>
    <AnimatePresence>
      {isOpen && (
        <motion.div 
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          onClick={onClose}
          className="fixed inset-0 bg-black/20 backdrop-blur-sm z-[60]"
        />
      )}
    </AnimatePresence>
    <motion.div 
      initial={{ x: '-100%' }}
      animate={{ x: isOpen ? 0 : '-100%' }}
      transition={{ type: 'spring', damping: 25, stiffness: 200 }}
      className="fixed top-0 left-0 bottom-0 w-72 bg-white shadow-2xl z-[70] p-6"
    >
      <div className="flex items-center justify-between mb-8">
        <div className="flex flex-col">
          <span className="font-bold text-lg text-primary">Event Manager</span>
          <span className="text-xs text-slate-400">Christ College Rajkot</span>
        </div>
        <button onClick={onClose} className="p-2 hover:bg-slate-100 rounded-lg">
          <X className="w-5 h-5 text-slate-500" />
        </button>
      </div>

      <div className="space-y-2">
        {[
          { id: 'home', label: 'Home', icon: LayoutDashboard },
          { id: 'add', label: 'Add Events', icon: PlusCircle },
          { id: 'view', label: 'View Events', icon: ListFilter },
          { id: 'attendance', label: 'Attendance', icon: Scan },
          { id: 'settings', label: 'Email Settings', icon: Settings },
        ].map((item) => (
          <button
            key={item.id}
            onClick={() => { setActiveTab(item.id); onClose(); }}
            className={cn(
              "w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all font-medium",
              activeTab === item.id 
                ? "bg-primary text-white shadow-lg shadow-primary/20" 
                : "text-slate-600 hover:bg-slate-50"
            )}
          >
            <item.icon className="w-5 h-5" />
            {item.label}
          </button>
        ))}
      </div>
    </motion.div>
  </>
);

// --- Main App ---

export default function App() {
  const [isAdmin, setIsAdmin] = useState(false);
  const [showLogin, setShowLogin] = useState(false);
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [activeTab, setActiveTab] = useState('home');
  const [events, setEvents] = useState<Event[]>([]);
  const [stats, setStats] = useState({ totalEvents: 0, activeForms: 0 });
  const [selectedEvent, setSelectedEvent] = useState<Event | null>(null);
  const [registrations, setRegistrations] = useState<Registration[]>([]);
  const [isRegistering, setIsRegistering] = useState<Event | null>(null);
  const [eventToEdit, setEventToEdit] = useState<Event | null>(null);

  // Login State
  const [loginName, setLoginName] = useState('');
  const [loginPass, setLoginPass] = useState('');

  useEffect(() => {
    if (!showLogin) {
      setLoginName('');
      setLoginPass('');
    }
  }, [showLogin]);

  const fetchEvents = async () => {
    try {
      const res = await fetch('/api/events');
      const data = await res.json();
      setEvents(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error('Failed to fetch events:', err);
      setEvents([]);
    }
  };

  const fetchStats = async () => {
    try {
      const res = await fetch('/api/stats');
      const data = await res.json();
      if (data && !data.error) {
        setStats(data);
      }
    } catch (err) {
      console.error('Failed to fetch stats:', err);
    }
  };

  useEffect(() => {
    fetchEvents();
    fetchStats();
  }, []);

  const handleLogin = (e: React.FormEvent) => {
    e.preventDefault();
    const isAdminUser = loginName === 'christcollegewebsite57@gmail.com' || loginName === 'rajeshwarinair827@gmail.com';
    const isValidPass = loginPass === 'CHRIST2026' || loginPass.replace(/\s/g, '') === 'bkezancriscnrttb';

    if (isAdminUser && isValidPass) {
      setIsAdmin(true);
      setShowLogin(false);
      setActiveTab('home');
      toast.success('Welcome back, Admin!');
    } else {
      toast.error('Invalid Credentials');
    }
  };

  const handleLogout = () => {
    setIsAdmin(false);
    setActiveTab('home');
    setEventToEdit(null);
  };

  const handleDeleteEvent = async (id: number) => {
    if (!id) return toast.error('Invalid Event ID');
    if (!confirm('Are you sure you want to delete this event? All registrations will also be deleted.')) return;
    
    try {
      const res = await fetch(`/api/events/${id}`, { 
        method: 'DELETE',
        headers: { 'Accept': 'application/json' }
      });
      
      const contentType = res.headers.get("content-type");
      let errorMessage = 'Failed to delete event';
      
      if (res.ok) {
        await fetchEvents();
        await fetchStats();
        toast.success('Event deleted successfully');
        return;
      }

      if (contentType && contentType.indexOf("application/json") !== -1) {
        const errorData = await res.json();
        errorMessage = errorData.error || errorMessage;
      } else {
        const textError = await res.text();
        console.error('Server returned non-JSON error:', textError);
        errorMessage = `Server Error: ${res.status}`;
      }
      
      toast.error(`Error: ${errorMessage}`);
    } catch (err) {
      console.error('Fetch error during delete:', err);
      toast.error(`Network error while deleting: ${err instanceof Error ? err.message : 'Unknown error'}`);
    }
  };

  const handleExportExcel = (event: Event, regs: Registration[]) => {
    if (regs.length === 0) return toast.info('No participants to export');
    
    const data = regs.map(r => ({
      'Registration ID': r.id,
      'Date': format(new Date(r.registeredAt), 'PPP p'),
      ...r.formData
    }));

    const ws = XLSX.utils.json_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Participants");
    XLSX.writeFile(wb, `${event.name}_participants.xlsx`);
  };

  return (
    <div className="min-h-screen bg-slate-50">
      <Toaster position="top-center" richColors />
      <Navbar 
        isAdmin={isAdmin} 
        onLoginClick={() => setShowLogin(true)} 
        onLogout={handleLogout}
        onToggleSidebar={() => setIsSidebarOpen(true)}
        showSidebarToggle={isAdmin}
        onLogoClick={() => {
          setIsAdmin(false);
          setActiveTab('home');
          setIsRegistering(null);
          setEventToEdit(null);
          window.scrollTo({ top: 0, behavior: 'smooth' });
        }}
      />
      
      {!isAdmin && <SubNavbar onExplore={() => document.getElementById('events-list')?.scrollIntoView({ behavior: 'smooth' })} />}

      <main className="max-w-7xl mx-auto px-4 py-8">
        {isAdmin ? (
          <AdminView 
            activeTab={activeTab} 
            stats={stats} 
            events={events} 
            eventToEdit={eventToEdit}
            registrations={registrations}
            selectedEvent={selectedEvent}
            onRefresh={() => { fetchEvents(); fetchStats(); }}
            onEditEvent={(event: Event) => {
              setEventToEdit(event);
              setActiveTab('add');
            }}
            onDeleteEvent={handleDeleteEvent}
            onViewDetails={async (event: Event) => {
              const res = await fetch(`/api/events/${event.id}/registrations`);
              const data = await res.json();
              setRegistrations(data);
              setSelectedEvent(event);
              setActiveTab('details');
            }}
            onCancelEdit={() => {
              setEventToEdit(null);
              setActiveTab('view');
            }}
            onExportExcel={handleExportExcel}
            onBackToView={() => setActiveTab('view')}
            onAddEvent={() => setActiveTab('add')}
            setActiveTab={setActiveTab}
          />
        ) : (
          <UserView 
            events={events} 
            onRegister={(event) => setIsRegistering(event)}
          />
        )}
      </main>

      {/* Login Modal */}
      <AnimatePresence>
        {showLogin && (
          <div className="fixed inset-0 flex items-center justify-center z-[100] px-4">
            <motion.div 
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              onClick={() => setShowLogin(false)}
              className="absolute inset-0 bg-black/40 backdrop-blur-sm"
            />
            <motion.div 
              initial={{ scale: 0.9, opacity: 0, y: 20 }}
              animate={{ scale: 1, opacity: 1, y: 0 }}
              exit={{ scale: 0.9, opacity: 0, y: 20 }}
              className="bg-white rounded-3xl shadow-2xl p-8 w-full max-w-md relative z-10"
            >
              <h2 className="text-2xl font-bold text-primary mb-6 text-center">Admin Access</h2>
              <form onSubmit={handleLogin} className="space-y-4" autoComplete="off">
                {/* Dummy fields to trick browser autofill */}
                <input type="text" name="prevent_autofill" style={{ display: 'none' }} tabIndex={-1} />
                <input type="password" name="password_fake" style={{ display: 'none' }} tabIndex={-1} />
                
                <div>
                  <label className="block text-sm font-semibold text-slate-700 mb-1">Admin Email</label>
                  <input 
                    type="text" 
                    name={`admin_user_${Math.random().toString(36).substring(7)}`}
                    value={loginName}
                    onChange={(e) => setLoginName(e.target.value)}
                    className="input-field" 
                    placeholder="Enter admin email"
                    required
                    autoComplete="off"
                  />
                </div>
                <div>
                  <label className="block text-sm font-semibold text-slate-700 mb-1">Password</label>
                  <input 
                    type="password" 
                    name={`admin_pass_${Math.random().toString(36).substring(7)}`}
                    value={loginPass}
                    onChange={(e) => setLoginPass(e.target.value)}
                    className="input-field" 
                    placeholder="••••••••"
                    required
                    autoComplete="new-password"
                  />
                </div>
                <button type="submit" className="btn-primary w-full py-3 mt-4">
                  Login to Dashboard
                </button>
              </form>
            </motion.div>
          </div>
        )}
      </AnimatePresence>

      {/* Registration Modal */}
      <AnimatePresence>
        {isRegistering && (
          <div className="fixed inset-0 flex items-center justify-center z-[100] px-4 overflow-y-auto py-10">
            <motion.div 
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              onClick={() => setIsRegistering(null)}
              className="fixed inset-0 bg-black/40 backdrop-blur-sm"
            />
            <motion.div 
              initial={{ scale: 0.9, opacity: 0, y: 20 }}
              animate={{ scale: 1, opacity: 1, y: 0 }}
              exit={{ scale: 0.9, opacity: 0, y: 20 }}
              className="bg-white rounded-3xl shadow-2xl p-8 w-full max-w-2xl relative z-10 my-auto"
            >
              <div className="flex justify-between items-start mb-6">
                <div>
                  <h2 className="text-2xl font-bold text-primary">{isRegistering.name}</h2>
                  <p className="text-slate-500 text-sm">Registration Form</p>
                </div>
                <button onClick={() => setIsRegistering(null)} className="p-2 hover:bg-slate-100 rounded-lg">
                  <X className="w-6 h-6 text-slate-400" />
                </button>
              </div>
              
              <RegistrationForm 
                event={isRegistering} 
                onSuccess={() => {
                  setIsRegistering(null);
                  toast.success('Registration Successful!');
                }} 
              />
            </motion.div>
          </div>
        )}
      </AnimatePresence>

      <Sidebar 
        isOpen={isSidebarOpen} 
        onClose={() => setIsSidebarOpen(false)} 
        activeTab={activeTab} 
        setActiveTab={setActiveTab} 
      />
    </div>
  );
}

// --- Views ---

function AdminView({ 
  activeTab, 
  stats, 
  events, 
  eventToEdit, 
  registrations, 
  selectedEvent, 
  onRefresh, 
  onViewDetails, 
  onEditEvent, 
  onDeleteEvent, 
  onCancelEdit,
  onExportExcel,
  onBackToView,
  onAddEvent,
  setActiveTab
}: any) {
  const safeEvents = Array.isArray(events) ? events : [];
  const [scanningEvent, setScanningEvent] = useState<Event | null>(null);

  // Reset scanning event if we switch tabs away from attendance
  useEffect(() => {
    if (activeTab !== 'attendance') {
      setScanningEvent(null);
    }
  }, [activeTab]);

  return (
    <div className="space-y-8">
      {activeTab === 'home' && (
        <div className="space-y-8">
          {/* Hero Slider in Admin View */}
          <HeroSlider 
            events={safeEvents} 
            isAdmin={true} 
            actionLabel="View Details" 
            onAction={onViewDetails} 
          />

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div className="glass-card p-8 flex items-center gap-6">
              <div className="w-16 h-16 bg-primary/10 rounded-2xl flex items-center justify-center">
                <Calendar className="w-8 h-8 text-primary" />
              </div>
              <div>
                <p className="text-slate-500 font-medium">Total Events</p>
                <h3 className="text-4xl font-bold text-slate-900">{stats.totalEvents}</h3>
              </div>
            </div>
            <div className="glass-card p-8 flex items-center gap-6">
              <div className="w-16 h-16 bg-accent/10 rounded-2xl flex items-center justify-center">
                <FileText className="w-8 h-8 text-accent" />
              </div>
              <div>
                <p className="text-slate-500 font-medium">Active Forms</p>
                <h3 className="text-4xl font-bold text-slate-900">{stats.activeForms}</h3>
              </div>
            </div>
            <div className="glass-card p-8 flex items-center gap-6">
              <div className="w-16 h-16 bg-emerald/10 rounded-2xl flex items-center justify-center">
                <UserCheck className="w-8 h-8 text-emerald-600" />
              </div>
              <div>
                <p className="text-slate-500 font-medium">Total Attendees</p>
                <h3 className="text-4xl font-bold text-slate-900">{stats.totalAttendees || 0}</h3>
              </div>
            </div>
            <button 
              onClick={onAddEvent}
              className="glass-card p-8 flex items-center gap-6 hover:bg-primary/5 transition-all group border-2 border-dashed border-primary/20"
            >
              <div className="w-16 h-16 bg-primary/10 rounded-2xl flex items-center justify-center group-hover:bg-primary group-hover:text-white transition-all">
                <PlusCircle className="w-8 h-8" />
              </div>
              <div className="text-left">
                <p className="text-slate-500 font-medium">Quick Action</p>
                <h3 className="text-2xl font-bold text-primary">Add New Event</h3>
              </div>
            </button>
          </div>

          <div className="glass-card p-8">
            <h3 className="text-xl font-bold text-primary mb-6">Recent Events</h3>
            <div className="space-y-4">
              {(Array.isArray(events) ? events : []).slice(0, 5).map((event: Event) => (
                <div key={event.id} className="flex items-center justify-between p-4 bg-slate-50 rounded-xl">
                  <span className="font-semibold text-slate-700">{event.name}</span>
                  <span className="text-sm text-slate-400">{format(new Date(event.startDate), 'PPP')}</span>
                </div>
              ))}
              {(!Array.isArray(events) || events.length === 0) && <p className="text-slate-400 italic">No events created yet.</p>}
            </div>
          </div>
        </div>
      )}

      {activeTab === 'add' && (
        <AddEventForm 
          eventToEdit={eventToEdit} 
          onSuccess={() => { 
            onRefresh(); 
            onCancelEdit(); // This will reset eventToEdit and switch to 'view'
          }} 
          onCancel={onCancelEdit}
        />
      )}

      {activeTab === 'view' && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {events.map((event: Event) => (
            <div key={event.id} className="glass-card overflow-hidden flex flex-col">
              {event.brochureUrl && (
                <img src={event.brochureUrl} alt={event.name} className="h-48 w-full object-cover" />
              )}
              <div className="p-6 flex-1 flex flex-col">
                <h3 className="text-lg font-bold text-primary mb-2">{event.name}</h3>
                <div className="space-y-2 text-sm text-slate-500 mb-6">
                  <div className="flex items-center gap-2">
                    <MapPin className="w-4 h-4" /> {event.venue}
                  </div>
                  <div className="flex items-center gap-2">
                    <Calendar className="w-4 h-4" /> {format(new Date(event.startDate), 'MMM d')} - {format(new Date(event.endDate), 'MMM d')}
                  </div>
                </div>
                
                <div className="mt-auto space-y-2">
                  <button 
                    onClick={() => onViewDetails(event)}
                    className="btn-primary w-full flex items-center justify-center gap-2"
                  >
                    <ListFilter className="w-4 h-4" />
                    View Details
                  </button>
                  <div className="grid grid-cols-2 gap-2">
                    <button 
                      onClick={() => onEditEvent(event)}
                      className="bg-slate-100 text-slate-700 px-4 py-2 rounded-full font-medium text-sm hover:bg-slate-200 transition-all flex items-center justify-center gap-2"
                    >
                      <PlusCircle className="w-4 h-4" />
                      Edit
                    </button>
                    <button 
                      onClick={() => onDeleteEvent(event.id)}
                      className="bg-red-50 text-red-600 px-4 py-2 rounded-full font-medium text-sm hover:bg-red-100 transition-all flex items-center justify-center gap-2"
                    >
                      <Trash2 className="w-4 h-4" />
                      Delete
                    </button>
                  </div>
                </div>
              </div>
            </div>
          ))}
          {events.length === 0 && (
            <div className="col-span-full py-20 text-center text-slate-400">
              No events found. Start by adding one!
            </div>
          )}
        </div>
      )}

      {activeTab === 'attendance' && (
        <div className="space-y-8">
          {!scanningEvent ? (
            <div className="space-y-8">
              <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                  <h2 className="text-3xl font-bold text-primary">Attendance Management</h2>
                  <p className="text-slate-500">Verify participant entry passes using QR scanning</p>
                </div>
                <button 
                  onClick={() => setScanningEvent({ id: -1, name: 'Global Scan' } as any)}
                  className="btn-primary flex items-center justify-center gap-2 py-3 px-8 shadow-lg shadow-primary/20"
                >
                  <QrCode className="w-5 h-5" />
                  Quick Scan (All Events)
                </button>
              </div>
              
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {events.map((event: Event) => (
                  <div key={event.id} className="glass-card p-6 flex flex-col group hover:border-primary/30 transition-all">
                    <div className="flex justify-between items-start mb-4">
                      <div className="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center group-hover:bg-primary group-hover:text-white transition-all">
                        <Calendar className="w-6 h-6" />
                      </div>
                      <span className="text-xs font-bold text-primary bg-primary/10 px-3 py-1 rounded-full">
                        {format(new Date(event.startDate), 'MMM d')}
                      </span>
                    </div>
                    <h3 className="text-lg font-bold text-slate-800 mb-2 truncate">{event.name}</h3>
                    <p className="text-sm text-slate-500 mb-6 flex items-center gap-2">
                      <MapPin className="w-4 h-4" /> {event.venue}
                    </p>
                    <button 
                      onClick={() => setScanningEvent(event)}
                      className="bg-slate-100 text-slate-600 w-full py-3 rounded-xl font-bold flex items-center justify-center gap-2 hover:bg-primary hover:text-white transition-all"
                    >
                      <Scan className="w-4 h-4" />
                      Event Specific Scan
                    </button>
                  </div>
                ))}
                {events.length === 0 && (
                  <div className="col-span-full py-20 text-center text-slate-400 glass-card">
                    <div className="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4">
                      <Calendar className="w-8 h-8 text-slate-200" />
                    </div>
                    No events available for attendance.
                  </div>
                )}
              </div>
            </div>
          ) : (
            <div className="space-y-8">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-4">
                  <button 
                    onClick={() => setScanningEvent(null)}
                    className="p-2 hover:bg-slate-100 rounded-full transition-all"
                  >
                    <ChevronLeft className="w-6 h-6 text-slate-600" />
                  </button>
                  <div>
                    <h2 className="text-2xl font-bold text-primary">
                      {scanningEvent.id === -1 ? 'Global Attendance Scanner' : `Scanning: ${scanningEvent.name}`}
                    </h2>
                    <p className="text-slate-500">
                      {scanningEvent.id === -1 
                        ? 'Scan any valid entry pass to mark attendance' 
                        : 'Only QR codes for this event will be accepted'}
                    </p>
                  </div>
                </div>
                {scanningEvent.id !== -1 && (
                  <button 
                    onClick={() => onViewDetails(scanningEvent)}
                    className="bg-slate-100 text-slate-600 px-6 py-2 rounded-xl font-semibold hover:bg-slate-200 transition-all flex items-center gap-2"
                  >
                    <UserCheck className="w-5 h-5" />
                    View List
                  </button>
                )}
              </div>

              <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div className="glass-card p-8">
                  <div className="flex items-center gap-3 mb-6">
                    <div className="w-10 h-10 bg-primary/10 rounded-xl flex items-center justify-center">
                      <Scan className="w-6 h-6 text-primary" />
                    </div>
                    <div>
                      <h3 className="text-xl font-bold text-slate-900">Live Camera</h3>
                      <p className="text-sm text-slate-500">
                        {scanningEvent.id === -1 ? 'Scanning for all active events' : `Scanning for ${scanningEvent.name}`}
                      </p>
                    </div>
                  </div>
                  <QRScanner 
                    onScanSuccess={async (decodedText) => {
                      try {
                        const data = JSON.parse(decodedText);
                        
                        // Validate event ID if not in global mode
                        if (scanningEvent.id !== -1 && data.eventId !== scanningEvent.id) {
                          toast.error(`Error: This QR code is for a different event. Please scan a pass for "${scanningEvent.name}".`);
                          return;
                        }

                        if (data.regId) {
                          const res = await fetch(`/api/registrations/${data.regId}/attendance`, {
                            method: 'POST'
                          });
                          if (res.ok) {
                            const result = await res.json();
                            toast.success(
                              <div className="flex flex-col">
                                <span className="font-bold">Attendance Marked!</span>
                                <span className="text-xs opacity-90">{result.registration.formData.Name || 'Participant'} - {result.registration.eventName}</span>
                              </div>
                            );
                            onRefresh();
                          } else {
                            const err = await res.json();
                            toast.error(err.error || "Failed to mark attendance");
                          }
                        }
                      } catch (e) {
                        console.error("Scan error:", e);
                        toast.error("Invalid QR Code format");
                      }
                    }} 
                  />
                </div>

                <div className="glass-card p-8 flex flex-col items-center justify-center text-center space-y-6">
                  <div className="w-24 h-24 bg-emerald-100 rounded-full flex items-center justify-center">
                    <UserCheck className="w-12 h-12 text-emerald-600" />
                  </div>
                  <div>
                    <h3 className="text-2xl font-bold text-slate-900">Scanner Ready</h3>
                    <p className="text-slate-500 max-w-xs mx-auto mt-2">
                      Position the participant's unique entry pass QR code in front of the camera.
                    </p>
                  </div>
                  <div className="w-full pt-6 border-t border-slate-100">
                    <div className="flex justify-between text-sm font-bold text-slate-400 uppercase tracking-widest mb-2">
                      <span>Camera Status</span>
                      <span className="text-emerald-600">Active</span>
                    </div>
                    <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                      <motion.div 
                        animate={{ x: [-100, 100] }}
                        transition={{ repeat: Infinity, duration: 2, ease: "linear" }}
                        className="h-full w-1/3 bg-primary/30"
                      />
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {activeTab === 'settings' && (
        <SettingsView />
      )}

      {activeTab === 'details' && selectedEvent && (
        <div className="glass-card p-8 space-y-6">
          <div className="flex justify-between items-center">
            <div>
              <h2 className="text-2xl font-bold text-primary">{selectedEvent.name}</h2>
              <div className="flex gap-4 mt-1">
                <p className="text-slate-500 text-sm">Total: {registrations.length}</p>
                <p className="text-emerald-600 text-sm font-bold">Attended: {registrations.filter(r => r.attended).length}</p>
              </div>
            </div>
            <div className="flex gap-3">
              <button 
                onClick={() => {
                  setScanningEvent(selectedEvent);
                  setActiveTab('attendance');
                }}
                className="btn-primary flex items-center gap-2"
              >
                <Scan className="w-4 h-4" />
                Scan Attendance
              </button>
              <button 
                onClick={() => onExportExcel(selectedEvent, registrations)}
                className="btn-accent flex items-center gap-2"
              >
                <Download className="w-4 h-4" />
                Export Excel
              </button>
              <button 
                onClick={onBackToView}
                className="bg-slate-100 text-slate-600 px-4 py-2 rounded-xl font-semibold hover:bg-slate-200 transition-all"
              >
                Back to List
              </button>
            </div>
          </div>

          <div className="overflow-auto border border-slate-100 rounded-2xl">
            <table className="w-full text-left border-collapse">
              <thead className="bg-slate-50 sticky top-0">
                <tr>
                  <th className="p-4 font-semibold text-slate-700 border-b">ID</th>
                  <th className="p-4 font-semibold text-slate-700 border-b">Date</th>
                  <th className="p-4 font-semibold text-slate-700 border-b">Attendance</th>
                  {selectedEvent.formConfig.map((field: any) => (
                    <th key={field.id} className="p-4 font-semibold text-slate-700 border-b">{field.label}</th>
                  ))}
                  <th className="p-4 font-semibold text-slate-700 border-b">Actions</th>
                </tr>
              </thead>
              <tbody>
                {registrations.map((reg: any) => (
                  <tr key={reg.id} className="hover:bg-slate-50 transition-colors">
                    <td className="p-4 border-b text-slate-600">{reg.id}</td>
                    <td className="p-4 border-b text-slate-600">{format(new Date(reg.registeredAt), 'MMM d, p')}</td>
                    <td className="p-4 border-b">
                      {reg.attended ? (
                        <span className="inline-flex items-center gap-1 px-2 py-1 bg-emerald-100 text-emerald-700 rounded-full text-xs font-bold">
                          <UserCheck className="w-3 h-3" />
                          Present
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2 py-1 bg-slate-100 text-slate-500 rounded-full text-xs font-bold">
                          <X className="w-3 h-3" />
                          Absent
                        </span>
                      )}
                    </td>
                    {selectedEvent.formConfig.map((field: any) => (
                      <td key={field.id} className="p-4 border-b text-slate-600">
                        {typeof reg.formData[field.label] === 'object' 
                          ? JSON.stringify(reg.formData[field.label]) 
                          : String(reg.formData[field.label] || '-')}
                      </td>
                    ))}
                    <td className="p-4 border-b text-slate-600">
                      <button
                        onClick={async () => {
                          try {
                            const res = await fetch(`/api/registrations/${reg.id}/resend-email`, { method: 'POST' });
                            if (res.ok) {
                              toast.success("Confirmation email resent successfully!");
                            } else {
                              const data = await res.json();
                              toast.error(data.error || "Failed to resend email. Check settings.");
                            }
                          } catch (err) {
                            toast.error("Error connecting to server.");
                          }
                        }}
                        className="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                        title="Resend Confirmation Email"
                      >
                        <Mail className="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {registrations.length === 0 && (
              <div className="p-12 text-center text-slate-400">
                No participants registered yet.
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function UserView({ events, onRegister }: { events: Event[], onRegister: (e: Event) => void }) {
  const safeEvents = Array.isArray(events) ? events : [];

  return (
    <div className="space-y-12">
      {/* Hero Slider */}
      <HeroSlider 
        events={safeEvents} 
        actionLabel="Register Now" 
        onAction={onRegister} 
      />

      {/* All Events List */}
      <section id="events-list" className="space-y-8">
        <div className="flex items-center justify-between">
          <h2 className="text-3xl font-bold text-slate-900">Upcoming Events</h2>
          <div className="h-1 flex-1 bg-slate-100 mx-8 rounded-full hidden sm:block" />
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
          {safeEvents.map((event) => {
            const now = new Date();
            const start = new Date(event.startDate);
            const end = new Date(event.endDate);
            const isUpcoming = isBefore(now, start);
            const isExpired = isAfter(now, end);
            const isActive = !isUpcoming && !isExpired;

            return (
              <motion.div 
                key={event.id}
                whileHover={{ y: -5 }}
                className="glass-card overflow-hidden flex flex-col group"
              >
                <div className="relative h-56 overflow-hidden">
                  {event.brochureUrl ? (
                    <img 
                      src={event.brochureUrl} 
                      alt={event.name} 
                      className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"
                    />
                  ) : (
                    <div className="w-full h-full bg-slate-100 flex items-center justify-center">
                      <Calendar className="w-12 h-12 text-slate-300" />
                    </div>
                  )}
                  <div className="absolute top-4 right-4">
                    <span className={cn(
                      "px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider shadow-lg",
                      isActive ? "bg-emerald-500 text-white" : 
                      isUpcoming ? "bg-amber-500 text-white" : "bg-slate-500 text-white"
                    )}>
                      {isActive ? 'Active' : isUpcoming ? 'Upcoming' : 'Expired'}
                    </span>
                  </div>
                </div>

                <div className="p-6 flex-1 flex flex-col">
                  <h3 className="text-xl font-bold text-primary mb-3">{event.name}</h3>
                  <p className="text-slate-500 text-sm line-clamp-3 mb-6 flex-1">{event.description}</p>
                  
                  <div className="space-y-3 mb-6">
                    <div className="flex items-center gap-3 text-sm text-slate-600">
                      <div className="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center">
                        <MapPin className="w-4 h-4 text-primary" />
                      </div>
                      {event.venue}
                    </div>
                    <div className="flex items-center gap-3 text-sm text-slate-600">
                      <div className="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center">
                        <Calendar className="w-4 h-4 text-primary" />
                      </div>
                      {format(new Date(event.startDate), 'MMM d')} - {format(new Date(event.endDate), 'MMM d, yyyy')}
                    </div>
                  </div>

                  <button 
                    disabled={!isActive}
                    onClick={() => onRegister(event)}
                    className={cn(
                      "w-full py-3 rounded-xl font-bold transition-all flex items-center justify-center gap-2",
                      isActive 
                        ? "bg-primary text-white hover:bg-primary/90 shadow-lg shadow-primary/20" 
                        : "bg-slate-200 text-slate-400 cursor-not-allowed"
                    )}
                  >
                    {isActive ? 'Register Now' : isUpcoming ? 'Registration Starts Soon' : 'Registration Closed'}
                  </button>
                </div>
              </motion.div>
            );
          })}
          {safeEvents.length === 0 && (
            <div className="col-span-full py-20 text-center">
              <div className="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <Calendar className="w-10 h-10 text-slate-300" />
              </div>
              <p className="text-slate-500 font-medium">No events currently scheduled.</p>
            </div>
          )}
        </div>
      </section>
    </div>
  );
}

function SettingsView() {
  const [settings, setSettings] = useState({
    emailService: 'gmail',
    emailUser: '',
    emailPass: '',
    emailFrom: '',
    emailSubject: '',
    emailMessage: '',
    emailHost: '',
    emailPort: 587,
    isConfigured: false,
    lastEmailError: null
  });
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [testEmail, setTestEmail] = useState('');
  const [isTesting, setIsTesting] = useState(false);
  const [isVerifying, setIsVerifying] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  useEffect(() => {
    fetchSettings();
  }, []);

  const fetchSettings = async () => {
    try {
      const res = await fetch('/api/settings');
      if (res.ok) {
        const data = await res.json();
        // If emailUser is empty, set the requested default
        if (!data.emailUser) {
          data.emailUser = 'christcollegewebsite57@gmail.com';
        }
        setSettings(data);
      }
    } catch (err) {
      toast.error("Failed to load settings");
    } finally {
      setIsLoading(false);
    }
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);
    try {
      const res = await fetch('/api/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(settings)
      });
      if (res.ok) {
        toast.success("Settings saved successfully");
        fetchSettings();
      } else {
        toast.error("Failed to save settings");
      }
    } catch (err) {
      toast.error("Error connecting to server");
    } finally {
      setIsSaving(false);
    }
  };

  const handleTestEmail = async () => {
    if (!testEmail) return toast.error("Please enter a test email address");
    setIsTesting(true);
    try {
      // Send current settings to test endpoint so we can test without saving first
      const res = await fetch('/api/settings/test', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
          testEmail,
          config: settings // Pass current UI state
        })
      });
      if (res.ok) {
        toast.success("Test email sent! Check your inbox.");
      } else {
        const data = await res.json();
        toast.error(data.error || "Failed to send test email");
      }
    } catch (err) {
      toast.error("Error connecting to server");
    } finally {
      setIsTesting(false);
    }
  };

  const handleVerifyConnection = async () => {
    setIsVerifying(true);
    try {
      const res = await fetch('/api/settings/verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(settings)
      });
      if (res.ok) {
        toast.success("Connection verified! SMTP settings are correct.");
      } else {
        const data = await res.json();
        toast.error(data.error || "Connection failed");
      }
    } catch (err) {
      toast.error("Error connecting to server");
    } finally {
      setIsVerifying(false);
    }
  };

  if (isLoading) return <div className="p-20 text-center">Loading settings...</div>;

  return (
    <div className="max-w-4xl mx-auto space-y-8">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold text-primary">Email Configuration</h2>
          <div className="flex items-center gap-2 mt-1">
            <p className="text-slate-500">Configure SMTP settings to send registration receipts</p>
            <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider ${settings.isConfigured ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}>
              {settings.isConfigured ? 'Active' : 'Not Configured'}
            </span>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div className="lg:col-span-2 space-y-6">
          <form onSubmit={handleSave} className="glass-card p-8 space-y-6">
            <div className="p-4 bg-amber-50 border border-amber-100 rounded-2xl space-y-3">
              <div className="flex items-start gap-3">
                <Info className="w-5 h-5 text-amber-600 mt-0.5" />
                <div className="space-y-1">
                  <p className="text-sm font-semibold text-amber-900">Gmail Setup Required</p>
                  <p className="text-xs text-amber-800 leading-relaxed">
                    For Gmail, you <strong>cannot</strong> use your regular password. You must use a 16-character <strong>App Password</strong>.
                  </p>
                </div>
              </div>
              <div className="pl-8 space-y-2">
                <p className="text-xs text-amber-800 font-medium">How to get an App Password:</p>
                <ol className="text-xs text-amber-700 list-decimal list-inside space-y-1">
                  <li>Go to your <a href="https://myaccount.google.com/security" target="_blank" rel="noopener noreferrer" className="underline font-bold">Google Account Security</a> page.</li>
                  <li>Enable <strong>2-Step Verification</strong> if not already on.</li>
                  <li>Search for "App Passwords" in the search bar at the top.</li>
                  <li>Create a new app password (select 'Other' and name it 'Christ College').</li>
                  <li>Copy the 16-character code and paste it below.</li>
                </ol>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-2">
                <label className="text-sm font-semibold text-slate-700">Email Service</label>
                <select 
                  value={settings.emailService}
                  onChange={(e) => setSettings({...settings, emailService: e.target.value})}
                  className="input-field"
                >
                  <option value="gmail">Gmail</option>
                  <option value="outlook">Outlook</option>
                  <option value="yahoo">Yahoo</option>
                  <option value="custom">Custom SMTP</option>
                </select>
              </div>
              <div className="space-y-2">
                <label className="text-sm font-semibold text-slate-700">Sender Name</label>
                <input 
                  type="text"
                  value={settings.emailFrom}
                  onChange={(e) => setSettings({...settings, emailFrom: e.target.value})}
                  placeholder="e.g. Christ College Events"
                  className="input-field"
                />
              </div>
            </div>

            {settings.emailService === 'custom' && (
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6 p-4 bg-slate-50 rounded-2xl border border-slate-100">
                <div className="md:col-span-2 space-y-2">
                  <label className="text-sm font-semibold text-slate-700">SMTP Host</label>
                  <input 
                    type="text"
                    value={settings.emailHost}
                    onChange={(e) => setSettings({...settings, emailHost: e.target.value})}
                    placeholder="smtp.example.com"
                    className="input-field bg-white"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-semibold text-slate-700">Port</label>
                  <input 
                    type="number"
                    value={settings.emailPort}
                    onChange={(e) => setSettings({...settings, emailPort: parseInt(e.target.value)})}
                    placeholder="587"
                    className="input-field bg-white"
                  />
                </div>
              </div>
            )}

              <div className="space-y-2">
                <label className="text-sm font-semibold text-slate-700">Sender Email (User)</label>
                <input 
                  type="email"
                  value={settings.emailUser}
                  onChange={(e) => setSettings({...settings, emailUser: e.target.value.trim()})}
                  placeholder="your-email@gmail.com"
                  className="input-field"
                  required
                />
                <p className="text-[10px] text-amber-600 font-medium">
                  Important: This MUST be the exact email address you used to generate the App Password.
                </p>
              </div>

              <div className="space-y-2">
                <label className="text-sm font-semibold text-slate-700">Email Subject</label>
                <input 
                  type="text"
                  value={settings.emailSubject}
                  onChange={(e) => setSettings({...settings, emailSubject: e.target.value})}
                  placeholder="e.g. Registration Confirmed: {event.name}"
                  className="input-field"
                />
                <p className="text-[10px] text-slate-400 italic">Leave empty to use default subject</p>
              </div>

              <div className="space-y-2">
                <label className="text-sm font-semibold text-slate-700">Custom Message (Optional)</label>
                <textarea 
                  value={settings.emailMessage}
                  onChange={(e) => setSettings({...settings, emailMessage: e.target.value})}
                  placeholder="Add a custom message to the confirmation email..."
                  className="input-field min-h-[100px] py-3"
                />
                <p className="text-[10px] text-slate-400 italic">This will be added before the event details</p>
              </div>

            <div className="space-y-2">
              <label className="text-sm font-semibold text-slate-700">App Password / Password</label>
              <div className="relative">
                <input 
                  type={showPassword ? "text" : "password"}
                  value={settings.emailPass}
                  onChange={(e) => {
                    let val = e.target.value;
                    // If it looks like a pasted Gmail App Password (has spaces and is around 19 chars)
                    // or if it's just being typed, we'll clean it on save/test, 
                    // but let's show it cleaned if they paste it with spaces.
                    if (settings.emailService === 'gmail' && val.includes(' ')) {
                      val = val.replace(/\s+/g, '');
                    }
                    setSettings({...settings, emailPass: val});
                  }}
                  placeholder="Enter app password"
                  className="input-field pr-10"
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                  title={showPassword ? "Hide Password" : "Show Password"}
                >
                  {showPassword ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
                </button>
              </div>
              {settings.emailService === 'gmail' && settings.emailPass && settings.emailPass !== '********' && (
                <div className={`flex items-center gap-1.5 p-2 rounded-lg border ${settings.emailPass.replace(/\s+/g, '').length === 16 ? 'bg-emerald-50 border-emerald-100 text-emerald-600' : 'bg-rose-50 border-rose-100 text-rose-500'}`}>
                  <Info className="w-3.5 h-3.5" />
                  <p className="text-[10px] font-medium leading-none">
                    {settings.emailPass.replace(/\s+/g, '').length === 16 
                      ? "Password length is correct (16 characters)." 
                      : `Gmail App Passwords must be exactly 16 characters. Current: ${settings.emailPass.replace(/\s+/g, '').length}`}
                  </p>
                </div>
              )}
              <p className="text-xs text-slate-400">
                For Gmail, use an <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noreferrer" className="text-primary underline">App Password</a>.
              </p>
            </div>

            <button 
              type="submit" 
              disabled={isSaving}
              className="btn-primary w-full py-3 flex items-center justify-center gap-2"
            >
              <Save className="w-5 h-5" />
              {isSaving ? 'Saving...' : 'Save Configuration'}
            </button>

            {settings.lastEmailError && (
              <div className="p-4 bg-red-50 border border-red-100 rounded-2xl">
                <div className="flex items-center justify-between mb-2">
                  <p className="text-xs font-bold text-red-400 uppercase tracking-wider">Last Error</p>
                  <button 
                    onClick={() => setSettings({...settings, lastEmailError: null})}
                    className="text-[10px] text-red-400 hover:text-red-600 underline"
                  >
                    Clear
                  </button>
                </div>
                <p className="text-sm text-red-700 font-mono break-words bg-white/50 p-2 rounded-lg border border-red-100/50">{settings.lastEmailError}</p>
                
                <div className="mt-4 pt-4 border-t border-red-100 space-y-3">
                  <p className="text-xs font-bold text-red-800 flex items-center gap-2">
                    <Info className="w-4 h-4" />
                    Troubleshooting Checklist:
                  </p>
                  <ul className="text-xs text-red-700 space-y-1.5 list-disc pl-4">
                    <li>Confirm <strong>2-Step Verification</strong> is ON in your Google Account.</li>
                    <li>Verify you used an <strong>App Password</strong> (16 characters), not your normal password.</li>
                    <li>Ensure the <strong>Sender Email</strong> matches the account you generated the password for.</li>
                    <li>Try deleting and <strong>re-generating</strong> a new App Password.</li>
                    <li>Check if your Gmail account has any <strong>security alerts</strong> waiting for approval.</li>
                  </ul>
                </div>
              </div>
            )}
          </form>
        </div>

        <div className="space-y-6">
          <div className="glass-card p-8 space-y-6">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 bg-amber-100 rounded-xl flex items-center justify-center">
                <Send className="w-6 h-6 text-amber-600" />
              </div>
              <h3 className="font-bold text-slate-900">Test Connection</h3>
            </div>
            <p className="text-sm text-slate-500">
              Send a test email to verify your SMTP settings are working correctly.
            </p>
            <div className="space-y-4">
              <input 
                type="email"
                value={testEmail}
                onChange={(e) => setTestEmail(e.target.value)}
                placeholder="Test recipient email"
                className="input-field"
              />
              <div className="space-y-2">
                <button 
                  onClick={handleTestEmail}
                  disabled={isTesting || isVerifying}
                  className="w-full py-3 bg-slate-100 text-slate-700 rounded-xl font-bold hover:bg-slate-200 transition-all flex items-center justify-center gap-2"
                >
                  {isTesting ? 'Sending...' : (
                    <>
                      <Send className="w-4 h-4" />
                      Send Test Email
                    </>
                  )}
                </button>
                <button 
                  onClick={handleVerifyConnection}
                  disabled={isTesting || isVerifying}
                  className="w-full py-2 text-xs font-semibold text-slate-500 hover:text-primary transition-colors flex items-center justify-center gap-2"
                >
                  {isVerifying ? 'Verifying...' : (
                    <>
                      <UserCheck className="w-3.5 h-3.5" />
                      Just verify connection (no email)
                    </>
                  )}
                </button>
              </div>
            </div>
          </div>

          <div className="bg-blue-50 p-6 rounded-3xl border border-blue-100">
            <h4 className="font-bold text-blue-900 mb-2 flex items-center gap-2">
              <Info className="w-4 h-4" /> Help Tip
            </h4>
            <p className="text-sm text-blue-700 leading-relaxed">
              Make sure to add an <strong>Email</strong> field to your event registration forms so participants can receive their receipts.
            </p>
          </div>

          <div className="bg-slate-50 p-6 rounded-3xl border border-slate-100 space-y-3">
            <h4 className="font-bold text-slate-900 flex items-center gap-2">
              <Info className="w-4 h-4 text-primary" /> Common Issues
            </h4>
            <ul className="text-xs text-slate-600 space-y-2 list-disc list-inside">
              <li><strong>Invalid Login (535):</strong> Ensure you use an <strong>App Password</strong>, not your regular password.</li>
              <li><strong>2-Step Verification:</strong> Must be enabled in your Google Account.</li>
              <li><strong>Spaces:</strong> The 16-character code should be entered without spaces.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  );
}

// --- Forms ---

function AddEventForm({ eventToEdit, onSuccess, onCancel }: { eventToEdit?: Event | null, onSuccess: () => void, onCancel?: () => void }) {
  const [name, setName] = useState(eventToEdit?.name || '');
  const [venue, setVenue] = useState(eventToEdit?.venue || '');
  const [startDate, setStartDate] = useState(eventToEdit?.startDate || '');
  const [endDate, setEndDate] = useState(eventToEdit?.endDate || '');
  const [description, setDescription] = useState(eventToEdit?.description || '');
  const [brochureUrl, setBrochureUrl] = useState(eventToEdit?.brochureUrl || '');
  const [formElements, setFormElements] = useState<FormElement[]>(eventToEdit?.formConfig || []);
  const [isGenerating, setIsGenerating] = useState(false);
  const [showSuccessModal, setShowSuccessModal] = useState<string | null>(null);

  useEffect(() => {
    if (eventToEdit) {
      setName(eventToEdit.name);
      setVenue(eventToEdit.venue);
      setStartDate(eventToEdit.startDate);
      setEndDate(eventToEdit.endDate);
      setDescription(eventToEdit.description);
      setBrochureUrl(eventToEdit.brochureUrl || '');
      setFormElements(eventToEdit.formConfig);
    }
  }, [eventToEdit]);

  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const reader = new FileReader();
      reader.onloadend = () => {
        setBrochureUrl(reader.result as string);
      };
      reader.readAsDataURL(file);
    }
  };

  const addFormElement = (type: FormElementType) => {
    const newElement: FormElement = {
      id: Math.random().toString(36).substr(2, 9),
      label: '',
      type,
      required: true,
      options: ['Option 1'],
      placeholder: '',
    };
    setFormElements([...formElements, newElement]);
  };

  const updateElement = (id: string, updates: Partial<FormElement>) => {
    setFormElements(formElements.map(el => el.id === id ? { ...el, ...updates } : el));
  };

  const removeElement = (id: string) => {
    setFormElements(formElements.filter(el => el.id !== id));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (formElements.length === 0) return toast.warning('Please add at least one form field');
    
    setIsGenerating(true);
    try {
      const url = eventToEdit ? `/api/events/${eventToEdit.id}` : '/api/events';
      const method = eventToEdit ? 'PUT' : 'POST';
      
      const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name, venue, startDate, endDate, description, brochureUrl, formConfig: formElements
        })
      });

      if (res.ok) {
        const data = await res.json();
        const eventId = eventToEdit ? eventToEdit.id : data.id;
        const link = `${window.location.origin}/#event-${eventId}`;
        
        setShowSuccessModal(link);
        // We don't call onSuccess() here yet, so the form stays open to show the modal
        
        if (!eventToEdit) {
          // Reset form
          setName(''); setVenue(''); setStartDate(''); setEndDate(''); 
          setDescription(''); setBrochureUrl(''); setFormElements([]);
        }
      } else {
        const errorData = await res.json();
        toast.error(`Error: ${errorData.error || 'Failed to save event'}`);
      }
    } catch (err) {
      console.error('Save Event Error:', err);
      toast.error('Network error while saving event');
    } finally {
      setIsGenerating(false);
    }
  };

  return (
    <div className="max-w-4xl mx-auto space-y-8">
      <div className="glass-card p-8">
        <div className="flex items-center justify-between mb-8">
          <h2 className="text-2xl font-bold text-primary flex items-center gap-3">
            <PlusCircle className="w-7 h-7" />
            {eventToEdit ? 'Edit Event' : 'Create New Event'}
          </h2>
          {eventToEdit && (
            <button 
              onClick={onCancel}
              className="text-slate-500 hover:text-slate-700 font-medium text-sm"
            >
              Cancel Edit
            </button>
          )}
        </div>
        
        <form onSubmit={handleSubmit} className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-semibold text-slate-700 mb-1">Event Name</label>
                <input 
                  type="text" 
                  value={name} 
                  onChange={(e) => setName(e.target.value)} 
                  className="input-field" 
                  placeholder="e.g. Annual Tech Fest 2026" 
                  required 
                />
              </div>
              <div>
                <label className="block text-sm font-semibold text-slate-700 mb-1">Venue</label>
                <input 
                  type="text" 
                  value={venue} 
                  onChange={(e) => setVenue(e.target.value)} 
                  className="input-field" 
                  placeholder="e.g. College Auditorium" 
                  required 
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-semibold text-slate-700 mb-1">Start Date</label>
                  <input 
                    type="datetime-local" 
                    value={startDate} 
                    onChange={(e) => setStartDate(e.target.value)} 
                    className="input-field" 
                    required 
                  />
                </div>
                <div>
                  <label className="block text-sm font-semibold text-slate-700 mb-1">End Date</label>
                  <input 
                    type="datetime-local" 
                    value={endDate} 
                    onChange={(e) => setEndDate(e.target.value)} 
                    className="input-field" 
                    required 
                  />
                </div>
              </div>
            </div>

            <div className="space-y-4">
              <div>
                <label className="block text-sm font-semibold text-slate-700 mb-1">Description</label>
                <textarea 
                  value={description} 
                  onChange={(e) => setDescription(e.target.value)} 
                  className="input-field h-32 resize-none" 
                  placeholder="Describe the event details..." 
                  required 
                />
              </div>
              <div>
                <label className="block text-sm font-semibold text-slate-700 mb-1">Brochure / Flyer</label>
                <div 
                  onClick={() => fileInputRef.current?.click()}
                  className="border-2 border-dashed border-slate-200 rounded-2xl p-4 text-center cursor-pointer hover:border-primary transition-colors bg-slate-50"
                >
                  {brochureUrl ? (
                    <div className="relative group">
                      <img src={brochureUrl} alt="Preview" className="h-24 mx-auto rounded-lg" />
                      <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 flex items-center justify-center transition-opacity rounded-lg">
                        <span className="text-white text-xs font-bold">Change Image</span>
                      </div>
                    </div>
                  ) : (
                    <div className="py-4">
                      <Plus className="w-8 h-8 text-slate-300 mx-auto mb-2" />
                      <p className="text-xs text-slate-500">Click to upload brochure</p>
                    </div>
                  )}
                </div>
                <input 
                  type="file" 
                  ref={fileInputRef} 
                  onChange={handleFileChange} 
                  className="hidden" 
                  accept="image/*" 
                />
              </div>
            </div>
          </div>

          <div className="border-t border-slate-100 pt-8 mt-8">
            <div className="flex items-center justify-between mb-6">
              <h3 className="text-xl font-bold text-slate-800">Registration Form Builder</h3>
              <div className="flex gap-2 overflow-x-auto pb-2">
                {[
                  { type: 'text', label: 'Short Answer' },
                  { type: 'email', label: 'Email' },
                  { type: 'tel', label: 'Phone' },
                  { type: 'number', label: 'Number' },
                  { type: 'radio', label: 'Multi Choice' },
                  { type: 'select', label: 'Dropdown' },
                  { type: 'date', label: 'Date' },
                  { type: 'textarea', label: 'Paragraph' },
                  { type: 'checkbox', label: 'Checkboxes' },
                  { type: 'file', label: 'File Upload' },
                  { type: 'section', label: 'Section' },
                ].map((btn) => (
                  <button
                    key={btn.type}
                    type="button"
                    onClick={() => addFormElement(btn.type as FormElementType)}
                    className="whitespace-nowrap px-3 py-1.5 bg-slate-100 hover:bg-primary hover:text-white rounded-lg text-xs font-semibold transition-all text-slate-600"
                  >
                    + {btn.label}
                  </button>
                ))}
                <button
                  type="button"
                  onClick={() => {
                    const emailField = {
                      id: Math.random().toString(36).substring(7),
                      type: 'email' as FormElementType,
                      label: 'Email',
                      required: true
                    };
                    setFormElements([...formElements, emailField]);
                    toast.success('Email field added! Required for receipts.');
                  }}
                  className="whitespace-nowrap px-3 py-1.5 bg-amber-100 hover:bg-amber-500 hover:text-white rounded-lg text-xs font-bold transition-all text-amber-700 border border-amber-200"
                >
                  + Quick Add Email
                </button>
              </div>
            </div>

            <div className="space-y-4">
              {formElements.map((el, index) => (
                <motion.div 
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  key={el.id} 
                  className="p-6 bg-slate-50 rounded-2xl border border-slate-100 relative group"
                >
                  <button 
                    type="button"
                    onClick={() => removeElement(el.id)}
                    className="absolute top-4 right-4 text-slate-300 hover:text-red-500 transition-colors"
                  >
                    <Trash2 className="w-5 h-5" />
                  </button>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-[10px] uppercase tracking-wider font-bold text-slate-400 mb-1">Field Label</label>
                      <input 
                        type="text" 
                        value={el.label} 
                        onChange={(e) => updateElement(el.id, { label: e.target.value })}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') {
                            e.preventDefault();
                            const next = document.getElementById(`field-label-${index + 1}`);
                            next?.focus();
                          }
                        }}
                        id={`field-label-${index}`}
                        className="input-field bg-white" 
                        placeholder="e.g. Full Name" 
                        required 
                      />
                    </div>
                    <div className="flex items-end gap-4">
                      <div className="flex-1">
                        <label className="block text-[10px] uppercase tracking-wider font-bold text-slate-400 mb-1">Type</label>
                        <div className="px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm font-medium text-slate-600">
                          {el.type.toUpperCase()}
                        </div>
                      </div>
                      <label className="flex items-center gap-2 mb-2 cursor-pointer">
                        <input 
                          type="checkbox" 
                          checked={el.required} 
                          onChange={(e) => updateElement(el.id, { required: e.target.checked })}
                          className="w-4 h-4 rounded border-slate-300 text-primary focus:ring-primary"
                        />
                        <span className="text-sm font-semibold text-slate-600">Required</span>
                      </label>
                    </div>
                  </div>

                  {['radio', 'checkbox', 'select'].includes(el.type) && (
                    <div className="mt-4">
                      <label className="block text-[10px] uppercase tracking-wider font-bold text-slate-400 mb-1">Options (Comma separated)</label>
                      <input 
                        type="text" 
                        value={el.options?.join(', ')} 
                        onChange={(e) => updateElement(el.id, { options: e.target.value.split(',').map(s => s.trim()) })}
                        className="input-field bg-white" 
                        placeholder="Option 1, Option 2, Option 3" 
                      />
                    </div>
                  )}
                </motion.div>
              ))}
              
              {formElements.length === 0 && (
                <div className="p-12 text-center border-2 border-dashed border-slate-100 rounded-3xl text-slate-400">
                  Click the buttons above to add fields to your registration form.
                </div>
              )}
            </div>
          </div>

          <div className="pt-8 flex gap-4">
            <button 
              type="submit" 
              disabled={isGenerating}
              className="btn-primary flex-1 py-4 text-lg flex items-center justify-center gap-2"
            >
              {isGenerating ? 'Saving...' : (
                <>
                  <CheckCircle2 className="w-6 h-6" />
                  {eventToEdit ? 'Update Event' : 'Generate Event & Form'}
                </>
              )}
            </button>
          </div>
        </form>
      </div>

      {/* Success Modal */}
      <AnimatePresence>
        {showSuccessModal && (
          <div className="fixed inset-0 flex items-center justify-center z-[150] px-4">
            <motion.div 
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              onClick={() => {
                setShowSuccessModal(null);
                onSuccess();
              }}
              className="absolute inset-0 bg-black/60 backdrop-blur-md"
            />
            <motion.div 
              initial={{ scale: 0.9, opacity: 0, y: 20 }}
              animate={{ scale: 1, opacity: 1, y: 0 }}
              exit={{ scale: 0.9, opacity: 0, y: 20 }}
              className="bg-white rounded-3xl shadow-2xl p-8 w-full max-w-lg relative z-10 text-center"
            >
              <div className="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <CheckCircle2 className="w-10 h-10 text-emerald-600" />
              </div>
              <h2 className="text-3xl font-bold text-slate-900 mb-2">Success!</h2>
              <p className="text-slate-500 mb-8">Your form has been successfully submitted and is now live.</p>
              
              <div className="bg-slate-50 p-4 rounded-2xl border border-slate-100 mb-8 text-left">
                <label className="block text-[10px] uppercase tracking-wider font-bold text-slate-400 mb-2">Form Public Link</label>
                <div className="flex items-center gap-3 bg-white p-3 rounded-xl border border-slate-200">
                  <input 
                    type="text" 
                    readOnly 
                    value={showSuccessModal}
                    className="flex-1 bg-transparent border-none focus:ring-0 text-sm font-medium text-slate-600"
                  />
                  <button 
                    onClick={() => {
                      navigator.clipboard.writeText(showSuccessModal);
                      toast.success('Link copied!');
                    }}
                    className="p-2 hover:bg-slate-100 rounded-lg transition-colors text-primary"
                    title="Copy Link"
                  >
                    <Copy className="w-5 h-5" />
                  </button>
                </div>
              </div>

              <button 
                onClick={() => {
                  setShowSuccessModal(null);
                  onSuccess();
                }}
                className="btn-primary w-full py-4 text-lg"
              >
                Got it, thanks!
              </button>
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </div>
  );
}

function RegistrationForm({ event, onSuccess }: { event: Event, onSuccess: () => void }) {
  const [formData, setFormData] = useState<Record<string, any>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [registrationResult, setRegistrationResult] = useState<Registration | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    
    try {
      const res = await fetch('/api/registrations', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ eventId: event.id, formData })
      });

      if (res.ok) {
        const data = await res.json();
        // The API returns { id: number }
        setRegistrationResult({
          id: data.id,
          eventId: event.id,
          formData: formData,
          attended: false,
          registeredAt: new Date().toISOString()
        });
      } else {
        const data = await res.json();
        toast.error(data.error || 'Registration failed');
      }
    } catch (error) {
      console.error("Form Error:", error);
      toast.error("Network error. Please check your connection.");
    } finally {
      setIsSubmitting(false);
    }
  };

  if (registrationResult) {
    return (
      <Receipt 
        registration={registrationResult} 
        event={event} 
        onBack={onSuccess} 
      />
    );
  }

  const handleFileChange = (label: string, e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const reader = new FileReader();
      reader.onloadend = () => {
        setFormData({ ...formData, [label]: reader.result });
      };
      reader.readAsDataURL(file);
    }
  };

  return (
    <form 
      onSubmit={handleSubmit} 
      className="space-y-6"
    >
      {event.formConfig.map((field, index) => (
        <div key={field.id} className="space-y-2">
          {field.type === 'section' ? (
            <div className="pt-6 pb-2 border-b border-slate-100">
              <h3 className="text-lg font-bold text-primary">{field.label}</h3>
            </div>
          ) : (
            <>
              <label 
                htmlFor={`field-${field.id}`}
                className="block text-sm font-semibold text-slate-700 cursor-pointer"
              >
                {field.label} {field.required && <span className="text-red-500">*</span>}
              </label>
              
              {field.type === 'textarea' ? (
                <textarea
                  id={`field-${field.id}`}
                  required={field.required}
                  autoFocus={index === 0}
                  onChange={(e) => setFormData({ ...formData, [field.label]: e.target.value })}
                  className="input-field h-24 resize-none"
                  placeholder={field.placeholder}
                />
              ) : field.type === 'select' ? (
                <select
                  id={`field-${field.id}`}
                  required={field.required}
                  autoFocus={index === 0}
                  onChange={(e) => setFormData({ ...formData, [field.label]: e.target.value })}
                  className="input-field"
                >
                  <option value="">Select an option</option>
                  {field.options?.map(opt => <option key={opt} value={opt}>{opt}</option>)}
                </select>
              ) : field.type === 'radio' ? (
                <div className="space-y-2">
                  {field.options?.map((opt, optIdx) => (
                    <label key={opt} className="flex items-center gap-3 p-3 bg-slate-50 rounded-xl cursor-pointer hover:bg-slate-100 transition-colors">
                      <input 
                        type="radio" 
                        name={field.id} 
                        required={field.required}
                        autoFocus={index === 0 && optIdx === 0}
                        onChange={() => setFormData({ ...formData, [field.label]: opt })}
                        className="w-4 h-4 text-primary focus:ring-primary"
                      />
                      <span className="text-sm font-medium text-slate-700">{opt}</span>
                    </label>
                  ))}
                </div>
              ) : field.type === 'checkbox' ? (
                <div className="space-y-2">
                  {field.options?.map((opt, optIdx) => (
                    <label key={opt} className="flex items-center gap-3 p-3 bg-slate-50 rounded-xl cursor-pointer hover:bg-slate-100 transition-colors">
                      <input 
                        type="checkbox" 
                        autoFocus={index === 0 && optIdx === 0}
                        onChange={(e) => {
                          const current = formData[field.label] || [];
                          const next = e.target.checked 
                            ? [...current, opt] 
                            : current.filter((v: string) => v !== opt);
                          setFormData({ ...formData, [field.label]: next });
                        }}
                        className="w-4 h-4 rounded text-primary focus:ring-primary"
                      />
                      <span className="text-sm font-medium text-slate-700">{opt}</span>
                    </label>
                  ))}
                </div>
              ) : field.type === 'file' ? (
                <input
                  id={`field-${field.id}`}
                  type="file"
                  required={field.required}
                  autoFocus={index === 0}
                  onChange={(e) => handleFileChange(field.label, e)}
                  className="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20"
                />
              ) : (
                <input
                  id={`field-${field.id}`}
                  type={field.type}
                  required={field.required}
                  autoFocus={index === 0}
                  onChange={(e) => setFormData({ ...formData, [field.label]: e.target.value })}
                  className="input-field"
                  placeholder={field.placeholder}
                />
              )}
            </>
          )}
        </div>
      ))}
      
      <button 
        type="submit" 
        disabled={isSubmitting}
        className="btn-primary w-full py-4 text-lg mt-8"
      >
        {isSubmitting ? 'Submitting...' : 'Complete Registration'}
      </button>
    </form>
  );
}
