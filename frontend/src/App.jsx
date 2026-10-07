import { useEffect, useMemo, useState } from 'react'
import { AsYouType, getCountryCallingCode, parsePhoneNumberFromString } from 'libphonenumber-js/max'
import registrationConfig from '../../shared/registration.json'
import heroImage from './assets/hero.png'
import {
  ArrowRight, ArrowUpRight, CalendarDays, Check,
  ChevronLeft, ChevronRight, CircleHelp, Eye, EyeOff,
  LockKeyhole, LogOut, Menu, Sparkles, Sun, Users, X,
} from 'lucide-react'
import './App.css'

const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const weekdays = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN']
const firstHolidayYear = 2020
const lastHolidayYear = 2027
const holidayTypeClass = (type) => ({
  'Regular Holiday': 'regular',
  'Special Non-Working Day': 'special',
  'Islamic Holiday': 'islamic',
}[type] || 'unmapped')
const api = (path, options = {}) => fetch(`/api${path}`, {
  ...options,
  credentials: 'same-origin',
  headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Pinggy-No-Screen': '1', ...(options.headers || {}) },
})
const csrfHeaders = async () => {
  const response = await fetch('/csrf-token', {
    headers: { Accept: 'application/json', 'X-Pinggy-No-Screen': '1' },
    credentials: 'same-origin',
  })
  const data = await response.json()
  if (!response.ok || typeof data.token !== 'string') throw new Error('Could not start a secure session. Please try again.')
  return { 'X-CSRF-TOKEN': data.token }
}
const initialForm = { first_name: '', last_name: '', middle_initial: '', birthday: '', email: '', mobile_number: '', country: '', house_street: '', city: '', state: '', region_code: '', city_code: '', zip_code: '', password: '', password_confirmation: '' }
const manilaDateParts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
}).formatToParts(new Date()).map(({ type, value }) => [type, value]))
const sessionToday = new Date(Number(manilaDateParts.year), Number(manilaDateParts.month) - 1, Number(manilaDateParts.day))
const initialMonth = new Date(sessionToday.getFullYear(), sessionToday.getMonth(), 1)
const initialQuery = new URLSearchParams(window.location.search)
const initialStatus = initialQuery.has('verified') ? 'verified' : initialQuery.has('unlocked') ? 'unlocked' : initialQuery.has('unlock_wait') ? 'unlock_wait' : null
const initialNotice = initialStatus === 'verified'
  ? 'Email verified. Sign in to finish setting up your account.'
  : initialStatus === 'unlocked'
    ? 'Your account has been unlocked. You can sign in now.'
    : initialStatus === 'unlock_wait'
      ? 'Your account is still on its two-minute security hold. Open the link again after the hold ends.'
      : ''

function App() {
  const [displayDate, setDisplayDate] = useState(() => initialMonth)
  const [selectedDate, setSelectedDate] = useState(() => sessionToday)
  const [holidays, setHolidays] = useState([])
  const [holidayErrors, setHolidayErrors] = useState({})
  const [holidayNotices, setHolidayNotices] = useState({})
  const [accounts, setAccounts] = useState([])
  const [accountsLoading, setAccountsLoading] = useState(true)
  const [accountsError, setAccountsError] = useState('')
  const [user, setUser] = useState(null)
  const [sessionLoading, setSessionLoading] = useState(true)
  const [modal, setModal] = useState('auth')
  const [authMode, setAuthMode] = useState('login')
  const [confirmPasswordVisible, setConfirmPasswordVisible] = useState(false)
  const [form, setForm] = useState(initialForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [notice, setNotice] = useState(initialNotice)
  const [busy, setBusy] = useState(true)
  const [verificationCode, setVerificationCode] = useState('')
  const [phoneHint, setPhoneHint] = useState('')
  const [resendIn, setResendIn] = useState(0)
  const [registrationCountries, setRegistrationCountries] = useState([])
  const [postalCodeFormats, setPostalCodeFormats] = useState({})
  const [loadedCities, setLoadedCities] = useState({ key: '', values: [] })
  const [statesByCountry, setStatesByCountry] = useState({})
  const [phAddressData, setPhAddressData] = useState({ regions: [], citiesByParent: {}, postalCodesByCity: {} })
  const [passwordVisible, setPasswordVisible] = useState(false)
  const [profileMenuOpen, setProfileMenuOpen] = useState(false)
  const [activeTab, setActiveTab] = useState('Holidays')
  const year = displayDate.getFullYear()
  const month = displayDate.getMonth()
  const selectedCountry = registrationCountries.find((country) => country.name === form.country)
  const selectedCountryCode = selectedCountry?.shortName || 'PH'
  const selectedCountryCallingCode = getCountryCallingCode(selectedCountryCode)
  const passwordMeetsRequirements = form.password.length >= 12
    && /[A-Z]/.test(form.password)
    && /[a-z]/.test(form.password)
    && /\d/.test(form.password)
    && /[^A-Za-z0-9]/.test(form.password)
  const stateOptions = statesByCountry[selectedCountryCode] || []
  const countryStatesLoaded = Object.hasOwn(statesByCountry, selectedCountryCode)
  const cityKey = `${selectedCountryCode}:${form.state}`
  const cityOptions = loadedCities.key === cityKey ? loadedCities.values : []
  const cityOptionsLoaded = loadedCities.key === cityKey
  const cityUsesRegionFallback = selectedCountryCode !== 'PH'
    && countryStatesLoaded
    && Boolean(form.state)
    && cityOptionsLoaded
    && cityOptions.length === 0
  const countryCityOptions = cityOptions.length > 0 ? cityOptions : cityUsesRegionFallback ? [form.state] : []
  const phCityOptions = phAddressData.citiesByParent[form.region_code] || []
  const caloocanPostalCodes = form.city.toLowerCase().includes('caloocan')
    ? [
      { postalCode: '1409', label: 'Kaunlaran Village' },
      { postalCode: '1411', label: 'Fish Market' },
    ]
    : []
  const phPostalOptions = [...new Map([
    ...(phAddressData.postalCodesByCity[form.city_code] || []),
    ...caloocanPostalCodes,
  ].map((postal) => [postal.postalCode, postal])).values()]
  const startupAuth = modal === 'auth' && !user

  function getFieldError(name, value, nextForm, input) {
    if (name === 'middle_initial' && !value) return ''
    if (!value) return input?.required ? 'This field is required.' : ''

    if (name === 'first_name' || name === 'last_name') {
      return value.length < 2 || value.length > 50 || !/^[\p{L}\p{M}]+(?:[ '-][\p{L}\p{M}]+)*$/u.test(value)
        ? 'Use 2–50 letters, spaces, hyphens, or apostrophes.'
        : ''
    }
    if (name === 'middle_initial') return /^[\p{L}\p{M}][.]?$/u.test(value) ? '' : 'Use one initial, optionally followed by a period.'
    if (name === 'birthday') {
      const match = /^(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])\/(\d{4})$/.exec(value)
      if (!match) return 'Enter a valid date as MM/DD/YYYY.'
      const [, monthPart, dayPart, yearPart] = match
      const birthYear = Number(yearPart)
      const birthMonth = Number(monthPart)
      const birthDay = Number(dayPart)
      const birthDate = new Date(Date.UTC(birthYear, birthMonth - 1, birthDay))
      if (birthDate.getUTCMonth() !== Number(monthPart) - 1 || birthDate.getUTCDate() !== Number(dayPart)) return 'Enter a valid calendar date.'
      const currentYear = Number(manilaDateParts.year)
      const currentMonth = Number(manilaDateParts.month)
      const currentDay = Number(manilaDateParts.day)
      let age = currentYear - birthYear
      let anniversaryMonth = birthMonth
      let anniversaryDay = birthDay

      if (birthMonth === 2 && birthDay === 29 && new Date(Date.UTC(currentYear, 1, 29)).getUTCDate() !== 29) {
        anniversaryMonth = 3
        anniversaryDay = 1
      }

      if (currentMonth < anniversaryMonth || (currentMonth === anniversaryMonth && currentDay < anniversaryDay)) {
        age -= 1
      }

      return age < 13 ? 'You must be at least 13 years old.' : ''
    }
    if (name === 'email') {
      if (input?.validity.typeMismatch) return 'Enter a valid email address.'
      const atIndex = value.lastIndexOf('@')
      const domain = atIndex > 0 ? value.slice(atIndex + 1).toLowerCase() : ''
      if (domain && !registrationConfig.public_email_domains.includes(domain)) {
        return 'Use a personal email address from a supported public email provider.'
      }
    }
    if (name === 'mobile_number') {
      const parsedNumber = parsePhoneNumberFromString(value, selectedCountryCode)
      if (!parsedNumber?.isPossible()) return 'Check the mobile number length for the selected country.'
      if (!parsedNumber.isValid()) return 'Check the mobile number format for the selected country.'
    }
    if (name === 'zip_code') {
      const format = postalCodeFormats[selectedCountryCode]
      if (!format) return 'Could not verify the postal code format. Please try again.'
      if (format.error) return format.error
      const postalCode = value.trim()
      const matchesFormat = format.pattern === null
        ? /^[\p{L}\p{N}][\p{L}\p{N} -]{0,19}$/u.test(postalCode)
        : new RegExp(`^(?:${format.pattern})$`, 'i').test(postalCode)
      return matchesFormat ? '' : 'Enter a valid postal code for the selected country.'
    }
    if (input?.validity.tooShort) return `Use at least ${input.minLength} characters.`
    if (input?.validity.tooLong) return `Use no more than ${input.maxLength} characters.`
    if (name === 'house_street' && !/^[\p{L}\p{M}\p{N}\s.,#'\x2F()-]+$/u.test(value)) return 'Use letters, numbers, and standard punctuation.'
    if (name === 'password' && authMode === 'register') {
      if (value.length < 12) return 'Use at least 12 characters.'
      if (!/[A-Z]/.test(value) || !/[a-z]/.test(value) || !/[0-9]/.test(value) || !/[^A-Za-z0-9]/.test(value)) return 'Include uppercase, lowercase, a number, and a special character.'
    }
    if (name === 'password_confirmation' && value !== nextForm.password) return 'Passwords do not match.'
    if (input?.validity.patternMismatch) return 'Enter a valid value.'
    return ''
  }

  function updateFormField(name, value, input, resetFields = []) {
    const nextForm = { ...form, [name]: value }
    resetFields.forEach((field) => { nextForm[field] = '' })
    setForm(nextForm)
    setFieldErrors((current) => {
      const nextErrors = { ...current }
      const error = getFieldError(name, value, nextForm, input)
      if (error) nextErrors[name] = error
      else delete nextErrors[name]
      resetFields.forEach((field) => { delete nextErrors[field] })
      if (name === 'password' && authMode === 'register' && nextForm.password_confirmation) {
        const confirmError = getFieldError('password_confirmation', nextForm.password_confirmation, nextForm)
        if (confirmError) nextErrors.password_confirmation = confirmError
        else delete nextErrors.password_confirmation
      }
      return nextErrors
    })
  }

  function updateVerificationCode(value) {
    const nextCode = value.replace(/\D/g, '').slice(0, 6)
    setVerificationCode(nextCode)
    setFieldErrors((current) => ({ ...current, code: nextCode && nextCode.length !== 6 ? 'Enter the six-digit code.' : '' }))
  }

  function validateAuthForm(formElement) {
    const errors = {}

    for (const input of formElement.elements) {
      if (!input.name) continue
      const error = getFieldError(input.name, input.value, form, input)
      if (error) errors[input.name] = error
    }

    if (authMode === 'verify' && verificationCode.length !== 6) {
      errors.code = 'Enter the six-digit code.'
    }

    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  function fieldError(name) {
    return fieldErrors[name] ? <span className="field-error" role="alert">{fieldErrors[name]}</span> : null
  }

  useEffect(() => {
    let active = true
    import('countrycitystatejson/countries').then(({ getCountries }) => {
      if (active) setRegistrationCountries(getCountries().sort((left, right) => left.name.localeCompare(right.name)))
    })
    return () => { active = false }
  }, [])

  useEffect(() => {
    let active = true
    api('/me', { cache: 'no-store' })
      .then(async (response) => {
        const data = await response.json()
        if (!active) return
        if (response.status === 401) {
          setNotice(initialNotice)
          return
        }
        if (!response.ok) throw new Error(data.message || 'Could not check your sign-in session.')
        if (!data || typeof data !== 'object' || !data.id) {
          throw new Error('The session service returned an invalid response.')
        }
        setUser(data)
        setModal(null)
      })
      .catch((error) => {
        if (active) setNotice(error.message || 'Could not check your sign-in session.')
      })
      .finally(() => {
        if (active) {
          setBusy(false)
          setSessionLoading(false)
        }
      })

    return () => { active = false }
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    api(`/holidays/${year}`, { signal: controller.signal })
      .then(async (response) => {
        const data = await response.json()
        if (!response.ok) throw new Error(data.message || `Could not load holidays for ${year}.`)
        if (!Array.isArray(data.holidays)) throw new Error('The holiday service returned an invalid response.')
        if (controller.signal.aborted) return
        setHolidays((current) => [
          ...current.filter((item) => !item.date.startsWith(`${year}-`)),
          ...data.holidays,
        ])
        setHolidayNotices((current) => {
          const next = { ...current }
          if (typeof data.notice === 'string') next[year] = data.notice
          else delete next[year]
          return next
        })
        setHolidayErrors((current) => {
          const next = { ...current }
          delete next[year]
          return next
        })
      })
      .catch((error) => {
        if (error.name !== 'AbortError' && !controller.signal.aborted) {
          setHolidays((current) => current.filter((item) => !item.date.startsWith(`${year}-`)))
          setHolidayErrors((current) => ({ ...current, [year]: error.message || `Could not load holidays for ${year}.` }))
        }
      })
    return () => controller.abort()
  }, [year])

  useEffect(() => {
    if (modal !== 'guide' || activeTab !== 'Accounts' || !user) return undefined
    const controller = new AbortController()
    api('/accounts', {
      signal: controller.signal,
    })
      .then(async (response) => {
        const data = await response.json()
        if (!response.ok) throw new Error(data.message || 'Could not load accessible accounts.')
        if (!Array.isArray(data.accounts)) throw new Error('The accounts service returned an invalid response.')
        setAccounts(data.accounts)
      })
      .catch((error) => {
        if (!controller.signal.aborted) setAccountsError(error.message || 'Could not load accessible accounts.')
      })
      .finally(() => {
        if (!controller.signal.aborted) setAccountsLoading(false)
      })

    return () => controller.abort()
  }, [activeTab, modal, user])

  useEffect(() => {
    let active = true
    import('countrycitystatejson/countries').then(({ getStatesByShort }) => {
      if (active) setStatesByCountry((current) => ({ ...current, [selectedCountryCode]: getStatesByShort(selectedCountryCode) || [] }))
    })
    return () => { active = false }
  }, [selectedCountryCode])

  useEffect(() => {
    let active = true
    api(`/postal-code-format/${selectedCountryCode}`)
      .then(async (response) => {
        const data = await response.json()
        if (!response.ok || data.country_code !== selectedCountryCode) {
          throw new Error(data.message || 'Could not verify the postal code format. Please try again.')
        }
        if (active) setPostalCodeFormats((current) => ({ ...current, [selectedCountryCode]: data }))
      })
      .catch((error) => {
        if (active) setPostalCodeFormats((current) => ({
          ...current,
          [selectedCountryCode]: { error: error.message || 'Could not verify the postal code format. Please try again.' },
        }))
      })
    return () => { active = false }
  }, [selectedCountryCode])

  useEffect(() => {
    if (selectedCountryCode !== 'PH') return undefined
    let active = true
    import('@aivangogh/ph-address').then((addressData) => {
      if (!active) return
      const citiesByParent = form.region_code
        ? { [form.region_code]: addressData.getAllMunicipalities().filter((city) => city.provinceCode === form.region_code || addressData.getProvinceByCode(city.provinceCode)?.regionCode === form.region_code) }
        : {}
      const postalCodesByCity = form.city_code
        ? { [form.city_code]: addressData.getPostalCodesByMunicipality(form.city_code) }
        : {}
      setPhAddressData((current) => ({
        regions: current.regions.length ? current.regions : addressData.getAllRegions(),
        citiesByParent: { ...current.citiesByParent, ...citiesByParent },
        postalCodesByCity: { ...current.postalCodesByCity, ...postalCodesByCity },
      }))
    }).catch(() => {})
    return () => { active = false }
  }, [selectedCountryCode, form.region_code, form.city_code])

  useEffect(() => {
    let active = true
    if (!form.state) return undefined
    import('countrycitystatejson/client').then(({ default: geoClient }) => geoClient.getCities(selectedCountryCode, form.state)).then((cities) => {
      if (active) setLoadedCities({ key: cityKey, values: cities || [] })
    }).catch(() => {
      if (active) setLoadedCities({ key: cityKey, values: [] })
    })
    return () => { active = false }
  }, [cityKey, form.state, selectedCountryCode])

  useEffect(() => {
    if (initialStatus) {
      window.history.replaceState({}, '', window.location.pathname)
    }
  }, [])

  useEffect(() => {
    if (!resendIn) return undefined
    const timer = window.setTimeout(() => setResendIn((current) => Math.max(0, current - 1)), 1000)
    return () => window.clearTimeout(timer)
  }, [resendIn])

  const visibleMonth = `${year}-${String(month + 1).padStart(2, '0')}`
  const monthHolidays = useMemo(
    () => holidays
      .filter((item) => /^\d{4}-\d{2}-\d{2}$/.test(item.date) && item.date.slice(0, 7) === visibleMonth)
      .sort((a, b) => a.date.localeCompare(b.date)),
    [holidays, visibleMonth],
  )
  const calendarDays = useMemo(() => {
    const firstWeekday = (new Date(year, month, 1).getDay() + 6) % 7
    const daysInMonth = new Date(year, month + 1, 0).getDate()
    const total = Math.ceil((firstWeekday + daysInMonth) / 7) * 7
    return Array.from({ length: total }, (_, index) => {
      const date = new Date(year, month, index - firstWeekday + 1)
      const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
      const inMonth = date.getMonth() === month
      return { date, key, inMonth, holiday: inMonth ? holidays.find((item) => item.date === key) : undefined }
    })
  }, [holidays, month, year])

  function changeMonth(amount) {
    const next = new Date(year, month + amount, 1)
    if (next.getFullYear() < firstHolidayYear || next.getFullYear() > lastHolidayYear) return
    setDisplayDate(next)
    setSelectedDate(next)
  }

  function changeYear(nextYear) {
    const next = new Date(nextYear, month, 1)
    setDisplayDate(next)
    setSelectedDate(next)
  }

  function openModal(name) {
    setNotice('')
    setFieldErrors({})
    setModal(name)
  }

  function openAuth(mode) {
    setAuthMode(mode)
    setForm(initialForm)
    setFieldErrors({})
    setNotice('')
    setVerificationCode('')
    setPhoneHint('')
    setPasswordVisible(false)
    setConfirmPasswordVisible(false)
    setModal('auth')
  }

  async function submitAuth(event) {
    event.preventDefault()
    if (!validateAuthForm(event.currentTarget)) return
    setBusy(true)
    setNotice('')
    setFieldErrors({})
    try {
      const path = authMode === 'register' ? '/register' : authMode === 'verify' ? '/mobile/verify' : '/login'
      let payload = authMode === 'verify' ? { email: form.email, password: form.password, code: verificationCode } : form
      if (authMode === 'register') {
        const selectedRegion = phAddressData.regions.find((region) => region.psgcCode === form.region_code)
        payload = {
          ...form,
          country_code: selectedCountryCode,
          state: selectedCountryCode === 'PH' ? selectedRegion?.name || '' : form.state,
          mobile_number: `+${selectedCountryCallingCode}${form.mobile_number.replace(/\D/g, '')}`,
        }
        delete payload.region_code
        delete payload.city_code
      }
      const headers = await csrfHeaders()
      const response = await api(path, { method: 'POST', headers, body: JSON.stringify(payload) })
      const data = await response.json()
      if (response.status === 403 && data.mobile_verification_required) {
        setAuthMode('verify')
        setPhoneHint(data.phone_hint)
        setResendIn(data.retry_after || 60)
        setNotice(data.debug_code ? `${data.message} Development code: ${data.debug_code}` : data.message)
        return
      }
      if (!response.ok) {
        const validationErrors = data.errors || {}
        setFieldErrors(Object.fromEntries(Object.entries(validationErrors).map(([name, messages]) => [name, messages[0]])))
        if (Object.keys(validationErrors).length > 0) return
        throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Something went wrong. Please try again.')
      }
      if (authMode === 'register') {
        setNotice(data.message || 'Your account is ready. Check your email for a verification link before signing in.')
        setAuthMode('login')
        setForm(initialForm)
        setFieldErrors({})
      } else {
        setUser(data.user)
        setModal(null)
        setForm(initialForm)
        setFieldErrors({})
      }
    } catch (error) {
      setNotice(error.message)
    } finally {
      setBusy(false)
    }
  }

  async function resendCode() {
    setBusy(true)
    try {
      const headers = await csrfHeaders()
      const response = await api('/mobile/resend', { method: 'POST', headers, body: JSON.stringify({ email: form.email, password: form.password }) })
      const data = await response.json()
      if (!response.ok) throw new Error(data.message || 'The code could not be sent.')
      setNotice(data.debug_code ? `${data.message} Development code: ${data.debug_code}` : data.message)
      setResendIn(data.retry_after || 60)
    } catch (error) {
      setNotice(error.message)
    } finally {
      setBusy(false)
    }
  }

  async function signOut() {
    try {
      const headers = await csrfHeaders()
      const response = await api('/logout', { method: 'POST', headers })
      const data = await response.json()
      if (!response.ok) throw new Error(data.message || 'Could not sign out.')
    } catch (error) {
      setNotice(error.message || 'Could not sign out.')
      setModal('auth')
      return
    }

    localStorage.removeItem('daymark_token')
    localStorage.removeItem('daymark_user')
    setUser(null)
    setAuthMode('login')
    setForm(initialForm)
    setFieldErrors({})
    setNotice('')
    setVerificationCode('')
    setModal('auth')
  }

  function generatePassword() {
    const alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%'
    const randomBytes = crypto.getRandomValues(new Uint8Array(16))
    const password = `Daymark!${Array.from(randomBytes, (byte) => alphabet[byte % alphabet.length]).join('')}A7`
    setForm((current) => ({ ...current, password, password_confirmation: password }))
    setFieldErrors((current) => {
      const nextErrors = { ...current }
      delete nextErrors.password
      delete nextErrors.password_confirmation
      return nextErrors
    })
    setPasswordVisible(false)
    setConfirmPasswordVisible(false)
  }

  const years = Array.from(
    { length: lastHolidayYear - firstHolidayYear + 1 },
    (_, index) => firstHolidayYear + index,
  )

  if (sessionLoading) {
    return <div className="session-loading" role="status">Checking your sign-in session…</div>
  }

  return (
    <div className="app-shell">
      {!startupAuth && <header className="topbar">
        <a className="brand" href="#home" aria-label="Daymark home"><span className="brand-mark"><Sun size={19} strokeWidth={2.2} /></span><span>daymark<span className="brand-dot">.</span></span></a>
        <nav className="main-nav" aria-label="Main navigation">
          <a className="nav-link active" href="#home"><CalendarDays size={16} />Dashboard</a>
          <a className="nav-link" href="#profile">Profile</a>
          <a className="nav-link" href="#settings">Settings</a>
          <a className="nav-link" href="#holiday-module" onClick={(event) => { event.preventDefault(); setActiveTab('Holidays'); openModal('guide') }}>Standard Philippine Holidays Module</a>
        </nav>
        <div className="top-actions">{user ? <div className="user-menu" onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setProfileMenuOpen(false) }}><button className="user-avatar" aria-label="Open your account menu" aria-expanded={profileMenuOpen} onClick={() => setProfileMenuOpen((current) => !current)}>{user.first_name?.[0] || user.name?.[0] || 'U'}</button>{profileMenuOpen && <div className="profile-dropdown" role="menu"><button role="menuitem" onClick={() => { setProfileMenuOpen(false); signOut() }}><LogOut size={15} />Logout</button></div>}</div> : <button className="button button-small button-outline" onClick={() => openAuth('login')}>Sign in <ArrowUpRight size={15} /></button>}<button className="mobile-menu" aria-label="Open menu" onClick={() => openModal('menu')}><Menu size={21} /></button></div>
      </header>}

      {!startupAuth && <main id="home">
        <section className="welcome-banner">
          <div className="welcome-copy">
            <span className="eyebrow"><span className="eyebrow-dot" />PHILIPPINE HOLIDAY CALENDAR</span>
            <h1>Plan your days<br />with <em>Daymark.</em></h1>
            <p>Official Philippine holidays, all in one place.</p>
            <button className="button button-light" onClick={() => { setActiveTab('Holidays'); openModal('guide') }}>View More <ArrowRight size={16} /></button>
          </div>
          <div className="banner-photo" role="img" aria-label="Philippine landscape" />
        </section>

      </main>}

      {modal && <div className={`modal-backdrop${modal === 'auth' ? ' auth-screen' : ''}`} onMouseDown={(event) => { if (event.target === event.currentTarget && !startupAuth) setModal(null) }}><section className={`modal-window${modal === 'auth' ? ' auth-modal' : ''}${modal === 'auth' && authMode === 'register' ? ' auth-register' : ''}`} role="dialog" aria-modal="true" aria-labelledby="modal-title">{modal === 'auth' && authMode !== 'register' && <aside className="auth-art" aria-hidden="true"><div className="auth-art-brand"><span className="auth-art-mark"><Sun size={18} /></span><span>daymark<span>.</span></span></div><img src={heroImage} alt="" /><div className="auth-art-copy"><span>DAYMARK · PHILIPPINES</span><strong>Make space for<br />what matters.</strong><p>Your days, thoughtfully gathered.</p></div></aside>}<div className={`modal-heading${modal === 'guide' ? ' guide-modal-heading' : ''}`}><div>{modal !== 'guide' && <span className="panel-kicker">DAYMARK · PHILIPPINES</span>}<h2 id="modal-title">{modal === 'auth' ? (authMode === 'register' ? 'Make room for you.' : 'Welcome back.') : modal === 'guide' ? 'Standard Philippine Holidays Module' : 'Navigation'}</h2>{modal !== 'guide' && <p>{modal === 'auth' ? (authMode === 'register' ? 'A few details and you’re all set.' : 'Sign in to your Daymark account.') : 'Go to a section.'}</p>}</div>{!startupAuth && <button className="icon-button close-modal" onClick={() => setModal(null)} aria-label="Close"><X size={19} /></button>}</div>
          {modal === 'auth' && <form className="auth-form" autoComplete="off" noValidate onSubmit={submitAuth}>
            {authMode === 'register' && <>
              <div className="form-row three-columns">
                <label>First name<input name="first_name" autoComplete="given-name" required minLength="2" maxLength="50" pattern="[\p{L}\p{M}]+([ '-][\p{L}\p{M}]+)*" value={form.first_name} onChange={(event) => updateFormField('first_name', event.target.value, event.currentTarget)} placeholder="First name" />{fieldError('first_name')}</label>
                <label>Last name<input name="last_name" autoComplete="family-name" required minLength="2" maxLength="50" pattern="[\p{L}\p{M}]+([ '-][\p{L}\p{M}]+)*" value={form.last_name} onChange={(event) => updateFormField('last_name', event.target.value, event.currentTarget)} placeholder="Last name" />{fieldError('last_name')}</label>
                <label>Middle initial <span className="optional">(optional)</span><input name="middle_initial" maxLength="2" pattern="[A-Za-z][.]?" value={form.middle_initial} onChange={(event) => updateFormField('middle_initial', event.target.value, event.currentTarget)} placeholder="e.g. A." />{fieldError('middle_initial')}</label>
              </div>
              <div className="form-row">
                <label>Birthday<input name="birthday" type="text" inputMode="numeric" autoComplete="bday" required pattern="(0[1-9]|1[0-2])/(0[1-9]|[12][0-9]|3[01])/[0-9]{4}" placeholder="MM/DD/YYYY" maxLength="10" value={form.birthday} onChange={(event) => { const digits = event.target.value.replace(/\D/g, '').slice(0, 8); const birthday = digits.length > 4 ? `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}` : digits.length > 2 ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits; updateFormField('birthday', birthday, event.currentTarget) }} />{fieldError('birthday')}</label>
                <label>Email address<input name="email" type="email" autoComplete="email" required value={form.email} onChange={(event) => updateFormField('email', event.target.value, event.currentTarget)} placeholder="you@example.com" />{fieldError('email')}</label>
              </div>
              <div className="form-row">
                <label>Country<select name="country" required value={form.country} onChange={(event) => updateFormField('country', event.target.value, event.currentTarget, ['mobile_number', 'state', 'city', 'region_code', 'city_code', 'zip_code'])}><option value="">Select a country</option>{registrationCountries.map((country) => <option key={country.shortName} value={country.name}>{country.name}</option>)}</select>{fieldError('country')}</label>
                <label>Mobile number<span className="phone-field"><span>+{selectedCountryCallingCode}</span><input name="mobile_number" type="tel" inputMode="tel" autoComplete="tel-national" required value={form.mobile_number} onChange={(event) => {
                  let digits = event.target.value.replace(/\D/g, '').slice(0, 15)
                  if (selectedCountryCode === 'PH' && digits.startsWith('0') && digits.length >= 11) digits = digits.slice(1)
                  updateFormField('mobile_number', new AsYouType(selectedCountryCode).input(digits), event.currentTarget)
                }} placeholder="Mobile number" /></span>{fieldError('mobile_number')}</label>
              </div>
              <label>House &amp; street<input name="house_street" autoComplete="street-address" required maxLength="255" pattern="[\p{L}\p{M}\p{N}\s.,#'\/()-]+" value={form.house_street} onChange={(event) => updateFormField('house_street', event.target.value, event.currentTarget)} placeholder="House number, street, and barangay" />{fieldError('house_street')}</label>
              <div className="form-row three-columns">
                {!selectedCountry
                  ? <label>State<select name="state" autoComplete="address-level1" required disabled value=""><option value="">Select a country first</option></select>{fieldError('state')}</label>
                  : selectedCountryCode === 'PH'
                  ? <label>State<select name="state" autoComplete="address-level1" required value={form.region_code} onChange={(event) => {
                    const region = phAddressData.regions.find((item) => item.psgcCode === event.target.value)
                    updateFormField('state', region?.name || '', event.currentTarget, ['city', 'zip_code', 'city_code'])
                    setForm((current) => ({ ...current, region_code: event.target.value, city_code: '', city: '', zip_code: '' }))
                  }}><option value="">Select a state</option>{phAddressData.regions.map((region) => <option key={region.psgcCode} value={region.psgcCode}>{region.name}</option>)}</select>{fieldError('state')}</label>
                  : <label>State{countryStatesLoaded && stateOptions.length === 0
                    ? <input name="state" autoComplete="address-level1" required disabled={!selectedCountry} maxLength="100" value={form.state} onChange={(event) => updateFormField('state', event.target.value, event.currentTarget)} placeholder="State" />
                    : <select name="state" autoComplete="address-level1" required disabled={!selectedCountry} value={form.state} onChange={(event) => updateFormField('state', event.target.value, event.currentTarget, ['city'])}><option value="">Select a state</option>{stateOptions.map((state) => <option key={state} value={state}>{state}</option>)}</select>}{fieldError('state')}</label>}
                <label>City<select name="city" autoComplete="address-level2" required disabled={selectedCountryCode === 'PH' ? !form.region_code || phCityOptions.length === 0 : !form.state || countryCityOptions.length === 0} value={selectedCountryCode === 'PH' ? form.city_code : form.city} onChange={(event) => {
                  if (selectedCountryCode === 'PH') {
                    const city = phCityOptions.find((item) => item.psgcCode === event.target.value)
                    updateFormField('city', city?.name || '', event.currentTarget, ['zip_code'])
                    setForm((current) => ({ ...current, city_code: event.target.value, zip_code: '' }))
                  } else {
                    updateFormField('city', event.target.value, event.currentTarget)
                  }
                }}><option value="">{selectedCountryCode === 'PH' ? 'Select a city' : 'Select a city or region'}</option>{(selectedCountryCode === 'PH' ? phCityOptions.map((city) => ({ value: city.psgcCode, name: city.name })) : countryCityOptions.map((city) => ({ value: city, name: city }))).map((city) => <option key={city.value} value={city.value}>{city.name}</option>)}</select>{fieldError('city')}</label>
                <label>ZIP code{selectedCountryCode === 'PH' ? <select name="zip_code" autoComplete="postal-code" required disabled={!form.city_code || phPostalOptions.length === 0} value={form.zip_code} onChange={(event) => updateFormField('zip_code', event.target.value, event.currentTarget)}><option value="">Select a ZIP code</option>{phPostalOptions.map((postal, index) => <option key={`${postal.postalCode}-${index}`} value={postal.postalCode}>{postal.label ? `${postal.postalCode} — ${postal.label}` : postal.postalCode}</option>)}</select> : <input name="zip_code" autoComplete="postal-code" required maxLength="20" value={form.zip_code} onChange={(event) => updateFormField('zip_code', event.target.value.toUpperCase(), event.currentTarget)} placeholder="ZIP / postal code" />}{fieldError('zip_code')}</label>
              </div>
            </>}
            {authMode !== 'register' && <label>Email address<input name="email" type="email" autoComplete="off" required value={form.email} onChange={(event) => updateFormField('email', event.target.value, event.currentTarget)} placeholder="you@example.com" />{fieldError('email')}</label>}
            <div className="password-control">
              <div className="password-label">
                <label htmlFor="account-password">Password</label>
                {authMode === 'register' && <button type="button" className="generate-link" onClick={generatePassword}><Sparkles size={13} />Suggest strong password</button>}
              </div>
              <span className="password-field">
                <input id="account-password" name="password" className={authMode === 'register' && passwordMeetsRequirements && !fieldErrors.password ? 'password-valid' : ''} type={passwordVisible ? 'text' : 'password'} autoComplete={authMode === 'register' ? 'new-password' : 'off'} required minLength={authMode === 'register' ? 12 : 1} value={form.password} onChange={(event) => updateFormField('password', event.target.value, event.currentTarget)} placeholder={authMode === 'register' ? 'At least 12 characters' : 'Your password'} />
                <button type="button" onClick={() => setPasswordVisible((current) => !current)} aria-label={passwordVisible ? 'Hide password' : 'Show password'}>{passwordVisible ? <EyeOff size={17} /> : <Eye size={17} />}</button>
              </span>
              {fieldError('password')}
            </div>
            {authMode === 'register' && <><div className="password-rules"><span className={form.password.length >= 12 ? 'rule-passed' : ''}><Check size={13} />12+ characters</span><span className={/[A-Z]/.test(form.password) ? 'rule-passed' : ''}><Check size={13} />One uppercase</span><span className={/[a-z]/.test(form.password) ? 'rule-passed' : ''}><Check size={13} />One lowercase</span><span className={/\d/.test(form.password) ? 'rule-passed' : ''}><Check size={13} />One number</span><span className={/[^A-Za-z0-9]/.test(form.password) ? 'rule-passed' : ''}><Check size={13} />One symbol</span></div><label>Confirm password<span className="password-field"><input name="password_confirmation" className={form.password_confirmation && form.password_confirmation === form.password && !fieldErrors.password_confirmation ? 'password-valid' : ''} type={confirmPasswordVisible ? 'text' : 'password'} autoComplete="new-password" required value={form.password_confirmation} onChange={(event) => updateFormField('password_confirmation', event.target.value, event.currentTarget)} placeholder="Enter your password again" /><button type="button" onClick={() => setConfirmPasswordVisible((current) => !current)} aria-label={confirmPasswordVisible ? 'Hide confirm password' : 'Show confirm password'}>{confirmPasswordVisible ? <EyeOff size={17} /> : <Eye size={17} />}</button></span>{fieldError('password_confirmation')}</label></>}
            {authMode === 'verify' && <div className="verification-code-field"><label htmlFor="verification-code">Six-digit code</label>{phoneHint && <span id="verification-phone-hint" className="optional">Sent to the number ending in {phoneHint}</span>}<input id="verification-code" name="code" inputMode="numeric" autoComplete="one-time-code" aria-describedby={phoneHint ? 'verification-phone-hint' : undefined} required pattern="[0-9]{6}" maxLength="6" value={verificationCode} onChange={(event) => updateVerificationCode(event.target.value)} placeholder="000000" />{fieldError('code')}</div>}
            {notice && <div className="form-notice" role="status"><CircleHelp size={16} />{notice}</div>}<button className="button button-primary submit-button" disabled={busy}>{busy ? 'One moment…' : authMode === 'register' ? 'Create your account' : authMode === 'verify' ? 'Verify number & sign in' : 'Sign in'}<ArrowRight size={16} /></button>{authMode === 'verify' ? <button type="button" className="resend-button" onClick={resendCode} disabled={busy || resendIn > 0}>Resend OTP</button> : <p className="auth-switch">{authMode === 'register' ? 'Already have an account?' : 'New to Daymark?'} <button type="button" onClick={() => openAuth(authMode === 'register' ? 'login' : 'register')}>{authMode === 'register' ? 'Sign in' : 'Create an account'}</button></p>}<div className="secure-note"><LockKeyhole size={13} />Your personal information is securely protected.</div>
          </form>}
          {modal === 'guide' && <>
            <div className="modal-tabs" role="tablist" aria-label="Dashboard views">
              <button role="tab" aria-selected={activeTab === 'Holidays'} className={activeTab === 'Holidays' ? 'selected-tab' : ''} onClick={() => setActiveTab('Holidays')}><CalendarDays size={15} />Calendars / Holidays</button>
              <button role="tab" aria-selected={activeTab === 'Accounts'} className={activeTab === 'Accounts' ? 'selected-tab' : ''} onClick={() => setActiveTab('Accounts')}><Users size={15} />Accounts</button>
            </div>
            {activeTab === 'Holidays' ? <div className="guide-content">
              <div className="guide-toolbar">
                <strong>Philippine holidays</strong>
                <div className="holiday-calendar-controls">
                  <button className="icon-button" aria-label="Previous month" disabled={year === firstHolidayYear && month === 0} onClick={() => changeMonth(-1)}><ChevronLeft size={18} /></button>
                  <span>{monthNames[month]} {year}</span>
                  <button className="icon-button" aria-label="Next month" disabled={year === lastHolidayYear && month === 11} onClick={() => changeMonth(1)}><ChevronRight size={18} /></button>
                  <select aria-label="Holiday year" value={year} onChange={(event) => changeYear(Number(event.target.value))}>{years.map((item) => <option key={item} value={item}>{item}</option>)}</select>
                </div>
              </div>
              {holidayErrors[year] && <p className="data-error" role="alert">{holidayErrors[year]}</p>}
              {holidayNotices[year] && <p className="data-notice" role="note">{holidayNotices[year]}</p>}
              <div className="holiday-calendar">
                <div className="weekday-row">{weekdays.map((day) => <span key={day}>{day}</span>)}</div>
                <div className="days-grid">{calendarDays.map(({ date, key, inMonth, holiday }) => {
                  const holidayClass = holiday ? holidayTypeClass(holiday.type) : ''
                  return <button key={key} className={`day-cell${inMonth ? '' : ' outside'}${date.toDateString() === selectedDate.toDateString() ? ' selected' : ''}${holiday ? ' has-holiday' : ''}`} onClick={() => setSelectedDate(date)} aria-label={`${monthNames[date.getMonth()]} ${date.getDate()}${holiday ? `, ${holiday.name}${holiday.estimated ? ', estimated' : ''}, ${holiday.type}` : ''}`}><span className={`day-number${holiday ? ` day-number-${holidayClass}` : ''}`}>{date.getDate()}</span>{holiday && <span className={`day-dot day-dot-${holidayClass}`} />}</button>
                })}</div>
              </div>
              <div className="calendar-legend" aria-label="Holiday categories">
                <span className="legend-regular"><i className="legend-dot regular" />Regular Holiday</span>
                <span className="legend-special"><i className="legend-dot special" />Special Non-Working Day</span>
                <span className="legend-islamic"><i className="legend-dot islamic" />Islamic Holiday</span>
              </div>
              <div className="guide-holiday-list">
                <h3>{monthNames[month]} holidays</h3>
                {monthHolidays.length ? monthHolidays.map((holiday) => {
                  const date = new Date(`${holiday.date}T00:00:00`)
                  return <div className="guide-row" key={`${holiday.date}-${holiday.name}`}><span className="guide-date">{date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', timeZone: 'Asia/Manila' })}</span><strong>{holiday.name}{holiday.estimated && <span className="estimated-label"> (Estimated)</span>}</strong><span className={`holiday-tag holiday-tag-${holidayTypeClass(holiday.type)}`}>{holiday.type}</span></div>
                }) : <p className="empty-holidays">No holidays this month.</p>}
              </div>
            </div> : <div className="accounts-view">
              {accountsLoading ? <p className="empty-holidays" role="status">Loading accounts…</p>
                : accountsError ? <p className="data-error" role="alert">{accountsError}</p>
                  : accounts.length ? <table className="accounts-table"><thead><tr><th scope="col">Account</th><th scope="col">Email</th><th scope="col">Status</th></tr></thead><tbody>{accounts.map((account) => <tr key={account.email}><td>{account.name}</td><td>{account.email}</td><td><span className={account.email_verified ? 'verified-tag' : 'unverified-tag'}>{account.email_verified ? 'Verified' : 'Unverified'}</span></td></tr>)}</tbody></table>
                    : <p className="empty-holidays">No accessible accounts found.</p>}
            </div>}
          </>}
          {modal === 'menu' && <nav className="mobile-nav-list" aria-label="Mobile navigation">
            <a href="#home" onClick={() => setModal(null)}><CalendarDays size={17} />Dashboard</a>
            <a href="#profile" onClick={() => setModal(null)}><Users size={17} />Profile</a>
            <a href="#settings" onClick={() => setModal(null)}><LockKeyhole size={17} />Settings</a>
            <a href="#holiday-module" onClick={(event) => { event.preventDefault(); setActiveTab('Holidays'); openModal('guide') }}><CalendarDays size={17} />Standard Philippine Holidays Module</a>
          </nav>}
        </section></div>}
    </div>
  )
}

export default App
